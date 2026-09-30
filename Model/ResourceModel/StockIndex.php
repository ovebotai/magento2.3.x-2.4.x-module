<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\ResourceModel;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Reads the quantity of many products at once, from the stock index Magento has already joined to the
 * product collection.
 *
 * Asking Magento for the salable quantity of one product costs about ten queries, which a feed of tens of
 * thousands of products cannot pay. The index holds the quantity in stock; with the inventory modules that work
 * with sources and stocks, the quantity kept for orders not shipped yet is in a table of its own and is summed
 * here in one query for a whole batch. Without those modules nothing is kept aside, so nothing is summed.
 */
class StockIndex
{
    /**
     * Name the product gets the quantity under
     */
    public const QUANTITY = 'ovebot_stock_quantity';

    /**
     * Name Magento joins the stock index under, with or without the inventory modules
     */
    private const JOIN_ALIAS = 'stock_status_index';

    private const RESERVATION_MODULE = 'Magento_InventoryReservations';
    private const RESERVATION_TABLE = 'inventory_reservation';
    private const DEFAULT_STOCK_ID = 1;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var ModuleManager
     */
    private $moduleManager;

    /**
     * @var array table of the index => name of its quantity column, empty when it has none
     */
    private $quantityColumns = [];

    /**
     * @var int stock of the index the last collection was joined to
     */
    private $stockId = self::DEFAULT_STOCK_ID;

    /**
     * @param ResourceConnection $resource
     * @param ModuleManager $moduleManager
     */
    public function __construct(ResourceConnection $resource, ModuleManager $moduleManager)
    {
        $this->resource = $resource;
        $this->moduleManager = $moduleManager;
    }

    /**
     * Add the quantity of the index to the products of a collection the stock was joined to
     *
     * @param Collection $collection
     * @return bool false when the quantity cannot be read this way
     */
    public function addQuantity(Collection $collection): bool
    {
        $from = $collection->getSelect()->getPart(Select::FROM);
        $table = isset($from[self::JOIN_ALIAS]['tableName']) ? $from[self::JOIN_ALIAS]['tableName'] : null;
        if (!is_string($table) || $table === '') {
            return false;
        }

        $column = $this->quantityColumn($table);
        if ($column === '') {
            return false;
        }

        $collection->getSelect()->columns([self::QUANTITY => self::JOIN_ALIAS . '.' . $column]);
        // the index of a stock of its own is named after the stock; the other one belongs to the default stock
        $this->stockId = preg_match('/inventory_stock_([0-9]+)\z/', $table, $match)
            ? (int) $match[1]
            : self::DEFAULT_STOCK_ID;

        return true;
    }

    /**
     * Quantity kept for orders that are not shipped yet, as a negative number
     *
     * @param string[] $skus
     * @return float[] SKU in lower case => quantity; a product with nothing kept is left out
     */
    public function getReserved(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map('strval', $skus), 'strlen')));
        if (!$skus || !$this->moduleManager->isEnabled(self::RESERVATION_MODULE)) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::RESERVATION_TABLE), ['sku', 'quantity' => 'SUM(quantity)'])
            ->where('stock_id = ?', $this->stockId)
            ->where('sku IN (?)', $skus)
            ->group('sku');

        $reserved = [];
        foreach ($connection->fetchAll($select) as $row) {
            $reserved[strtolower((string) $row['sku'])] = (float) $row['quantity'];
        }

        return $reserved;
    }

    /**
     * Column of a stock index that holds the quantity
     *
     * @param string $table
     * @return string empty when there is none
     */
    private function quantityColumn(string $table): string
    {
        if (!isset($this->quantityColumns[$table])) {
            $this->quantityColumns[$table] = '';
            try {
                $columns = array_keys($this->resource->getConnection()->describeTable($table));
            } catch (\Exception $e) {
                $columns = [];
            }
            foreach (['quantity', 'qty'] as $name) {
                if (in_array($name, $columns, true)) {
                    $this->quantityColumns[$table] = $name;
                    break;
                }
            }
        }

        return $this->quantityColumns[$table];
    }
}
