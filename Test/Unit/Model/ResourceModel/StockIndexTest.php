<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\ResourceModel;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager as ModuleManager;
use Ovebot\Chat\Model\ResourceModel\StockIndex;
use PHPUnit\Framework\TestCase;

class StockIndexTest extends TestCase
{
    /**
     * @var array table => columns
     */
    private $tables = [
        'cataloginventory_stock_status' => ['product_id', 'website_id', 'stock_id', 'qty', 'stock_status'],
        'inventory_stock_3' => ['sku', 'quantity', 'is_salable'],
        'some_other_index' => ['product_id', 'in_stock'],
    ];

    /**
     * @var string[] tables described, in order
     */
    private $described = [];

    /**
     * @var array conditions of the reservations query
     */
    private $where = [];

    /**
     * @var array rows the reservations query gives
     */
    private $rows = [];

    /**
     * @var int
     */
    private $queries = 0;

    /**
     * @var bool
     */
    private $reservationsEnabled = true;

    protected function setUp(): void
    {
        $this->described = [];
        $this->where = [];
        $this->rows = [];
        $this->queries = 0;
        $this->reservationsEnabled = true;
    }

    private function stockIndex(): StockIndex
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($condition, $value) use ($select) {
            $this->where[$condition] = $value;

            return $select;
        });

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('describeTable')->willReturnCallback(function ($table) {
            $this->described[] = $table;
            if (!isset($this->tables[$table])) {
                throw new \RuntimeException('no such table');
            }

            return array_fill_keys($this->tables[$table], []);
        });
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnCallback(function () {
            $this->queries++;

            return $this->rows;
        });

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturnCallback(function ($module) {
            return $module === 'Magento_InventoryReservations' && $this->reservationsEnabled;
        });

        return new StockIndex($resource, $moduleManager);
    }

    /**
     * Collection with the stock index joined from a table; null = the stock was not joined
     */
    private function collection(?string $table, array &$columns): Collection
    {
        $from = ['e' => ['tableName' => 'catalog_product_entity']];
        if ($table !== null) {
            $from['stock_status_index'] = ['tableName' => $table];
        }

        $select = $this->createMock(Select::class);
        $select->method('getPart')->with(Select::FROM)->willReturn($from);
        $select->method('columns')->willReturnCallback(function ($added) use (&$columns, $select) {
            $columns[] = $added;

            return $select;
        });

        $collection = $this->createMock(Collection::class);
        $collection->method('getSelect')->willReturn($select);

        return $collection;
    }

    /**
     * @dataProvider indexes
     */
    public function testQuantityColumnOfTheIndex(?string $table, bool $added, array $expected)
    {
        $columns = [];

        $this->assertSame($added, $this->stockIndex()->addQuantity($this->collection($table, $columns)));
        $this->assertSame($expected, $columns);
    }

    public static function indexes(): array
    {
        return [
            'stock of Magento_CatalogInventory, also the default stock of the inventory modules' => [
                'cataloginventory_stock_status',
                true,
                [['ovebot_stock_quantity' => 'stock_status_index.qty']],
            ],
            'stock of its own' => [
                'inventory_stock_3',
                true,
                [['ovebot_stock_quantity' => 'stock_status_index.quantity']],
            ],
            'index without a quantity' => ['some_other_index', false, []],
            'table that cannot be described' => ['missing_table', false, []],
            'stock not joined' => [null, false, []],
        ];
    }

    public function testIndexIsDescribedOnce()
    {
        $stockIndex = $this->stockIndex();
        $columns = [];

        $stockIndex->addQuantity($this->collection('inventory_stock_3', $columns));
        $stockIndex->addQuantity($this->collection('inventory_stock_3', $columns));

        $this->assertSame(['inventory_stock_3'], $this->described);
        $this->assertCount(2, $columns);
    }

    public function testReservationsAreSummedForTheStockOfTheIndex()
    {
        $stockIndex = $this->stockIndex();
        $columns = [];
        $stockIndex->addQuantity($this->collection('inventory_stock_3', $columns));
        $this->rows = [['sku' => 'Sku-A', 'quantity' => '-3.0000'], ['sku' => 'SKU-B', 'quantity' => '0.0000']];

        $reserved = $stockIndex->getReserved(['Sku-A', 'SKU-B', 'SKU-B', '', 'SKU-C']);

        $this->assertSame(['sku-a' => -3.0, 'sku-b' => 0.0], $reserved);
        $this->assertSame(['stock_id = ?' => 3, 'sku IN (?)' => ['Sku-A', 'SKU-B', 'SKU-C']], $this->where);
        $this->assertSame(1, $this->queries);
    }

    public function testDefaultStock()
    {
        $stockIndex = $this->stockIndex();
        $columns = [];
        $stockIndex->addQuantity($this->collection('cataloginventory_stock_status', $columns));

        $stockIndex->getReserved(['SKU-A']);

        $this->assertSame(1, $this->where['stock_id = ?']);
    }

    public function testNothingIsAskedWithoutReservationsOrWithoutProducts()
    {
        $this->assertSame([], $this->stockIndex()->getReserved([]));

        $this->reservationsEnabled = false;
        $this->assertSame([], $this->stockIndex()->getReserved(['SKU-A']));

        $this->assertSame(0, $this->queries);
    }
}
