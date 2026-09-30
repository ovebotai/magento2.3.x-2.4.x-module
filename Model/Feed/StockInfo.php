<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Feed;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Ovebot\Chat\Model\ResourceModel\StockIndex;

/**
 * Availability and quantity of products that are already known to be salable.
 *
 * Everything is read for a batch of products at once: the stock settings in one query, the quantity with the
 * products themselves (StockIndex), what is kept for orders in one more query. The salable quantity is what
 * Magento itself computes: quantity in stock, less what is kept for orders, less the out of stock threshold.
 *
 * Only a product that came without a quantity is asked from the stock registry of Magento, one by one.
 */
class StockInfo
{
    public const IN_STOCK = 'in_stock';
    public const PREORDER = 'preorder';

    /**
     * @var StockItemRepositoryInterface
     */
    private $stockItems;

    /**
     * @var StockItemCriteriaInterfaceFactory
     */
    private $criteriaFactory;

    /**
     * @var StockRegistryInterface
     */
    private $stockRegistry;

    /**
     * @var StockConfigurationInterface
     */
    private $stockConfiguration;

    /**
     * @var StockRegistryStorage
     */
    private $storage;

    /**
     * @var StockIndex
     */
    private $stockIndex;

    /**
     * @param StockItemRepositoryInterface $stockItems
     * @param StockItemCriteriaInterfaceFactory $criteriaFactory
     * @param StockRegistryInterface $stockRegistry
     * @param StockConfigurationInterface $stockConfiguration
     * @param StockRegistryStorage $storage
     * @param StockIndex $stockIndex
     */
    public function __construct(
        StockItemRepositoryInterface $stockItems,
        StockItemCriteriaInterfaceFactory $criteriaFactory,
        StockRegistryInterface $stockRegistry,
        StockConfigurationInterface $stockConfiguration,
        StockRegistryStorage $storage,
        StockIndex $stockIndex
    ) {
        $this->stockItems = $stockItems;
        $this->criteriaFactory = $criteriaFactory;
        $this->stockRegistry = $stockRegistry;
        $this->stockConfiguration = $stockConfiguration;
        $this->storage = $storage;
        $this->stockIndex = $stockIndex;
    }

    /**
     * Availability and quantity of salable products loaded through ProductSelection
     *
     * @param Product[] $products by product id
     * @param int $websiteId
     * @return array product id => {availability: in_stock|preorder, quantity: int|null}
     */
    public function forProducts(array $products, int $websiteId): array
    {
        $rows = [];
        foreach ($products as $productId => $product) {
            $quantity = $product->getData(StockIndex::QUANTITY);
            $rows[$productId] = [
                'type' => (string) $product->getTypeId(),
                'sku' => (string) $product->getSku(),
                'quantity' => $quantity === null ? null : (float) $quantity,
            ];
        }

        return $this->forRows($rows, $websiteId);
    }

    /**
     * Availability and quantity from the plain values of the products
     *
     * @param array $rows product id => {type: string, sku: string, quantity: float|null, as the stock index has it}
     * @param int $websiteId
     * @return array product id => {availability: in_stock|preorder, quantity: int|null}
     */
    public function forRows(array $rows, int $websiteId): array
    {
        $withQuantity = [];
        foreach ($rows as $productId => $row) {
            // a bundle, a grouped or a configurable product has no quantity of its own
            if ($this->stockConfiguration->isQty($row['type'])) {
                $withQuantity[] = (int) $productId;
            }
        }
        $settings = $this->settings($withQuantity);

        $skus = [];
        foreach ($settings as $productId => $setting) {
            if ($setting['managed'] && $rows[$productId]['quantity'] !== null) {
                $skus[] = $rows[$productId]['sku'];
            }
        }
        $reserved = $this->stockIndex->getReserved($skus);

        $result = [];
        $asked = false;
        foreach ($rows as $productId => $row) {
            $setting = isset($settings[$productId]) ? $settings[$productId] : null;
            $managed = $setting !== null && $setting['managed'];

            $quantity = 0.0;
            if ($managed && $row['quantity'] !== null) {
                $sku = strtolower($row['sku']);
                $quantity = $row['quantity'] + (isset($reserved[$sku]) ? $reserved[$sku] : 0.0) - $setting['threshold'];
            } elseif ($managed) {
                $asked = true;
                $quantity = $this->ask((int) $productId, $websiteId, $setting['quantity'] - $setting['threshold']);
            }

            $result[$productId] = $this->resolve($setting !== null, $managed, $quantity);
        }

        if ($asked) {
            // the registry keeps what it reads for the whole request; the feed must not grow with the catalog
            $this->storage->clean();
        }

        return $result;
    }

    /**
     * Availability and quantity from what is known about a salable product
     *
     * @param bool $hasQuantity whether the product type has a quantity of its own
     * @param bool $managed whether the stock of the product is managed
     * @param float $quantity salable quantity
     * @return array {availability: in_stock|preorder, quantity: int|null}
     */
    public function resolve(bool $hasQuantity, bool $managed, float $quantity): array
    {
        if (!$hasQuantity || !$managed) {
            return ['availability' => self::IN_STOCK, 'quantity' => null];
        }

        if ($quantity > 0) {
            // a quantity sold by weight or length can be below one unit
            $units = (int) floor($quantity);

            return ['availability' => self::IN_STOCK, 'quantity' => $units > 0 ? $units : null];
        }

        // salable with nothing in stock: the product is sold on backorder
        return ['availability' => self::PREORDER, 'quantity' => null];
    }

    /**
     * Salable quantity of one product, asked from Magento; the fallback when it cannot be read
     *
     * @param int $productId
     * @param int $websiteId
     * @param float $fallback
     * @return float
     */
    private function ask(int $productId, int $websiteId, float $fallback): float
    {
        try {
            return (float) $this->stockRegistry->getStockStatus($productId, $websiteId)->getQty();
        } catch (\Exception $e) {
            return $fallback;
        }
    }

    /**
     * Stock settings of products, read in one query
     *
     * @param int[] $productIds
     * @return array product id => {managed: bool, quantity: float, threshold: float}
     */
    private function settings(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $criteria = $this->criteriaFactory->create();
        $criteria->setProductsFilter($productIds);

        $settings = [];
        foreach ($this->stockItems->getList($criteria)->getItems() as $stockItem) {
            $productId = (int) $stockItem->getProductId();
            if (!isset($settings[$productId])) {
                $settings[$productId] = [
                    'managed' => (bool) $stockItem->getManageStock(),
                    'quantity' => (float) $stockItem->getQty(),
                    'threshold' => (float) $stockItem->getMinQty(),
                ];
            }
        }

        foreach ($productIds as $productId) {
            if (!isset($settings[$productId])) {
                // no stock record: the setting of the store decides
                $settings[$productId] = [
                    'managed' => (bool) $this->stockConfiguration->getManageStock(),
                    'quantity' => 0.0,
                    'threshold' => 0.0,
                ];
            }
        }

        return $settings;
    }
}
