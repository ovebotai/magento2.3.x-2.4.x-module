<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Feed;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemCollectionInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Ovebot\Chat\Model\Feed\StockInfo;
use Ovebot\Chat\Model\ResourceModel\StockIndex;
use PHPUnit\Framework\TestCase;

class StockInfoTest extends TestCase
{
    /**
     * @var array product id => [manage stock, quantity, out of stock threshold]
     */
    private $stockItems = [];

    /**
     * @var array SKU in lower case => quantity kept for orders, negative
     */
    private $reserved = [];

    /**
     * @var array product id => salable quantity the registry gives, or an exception to raise
     */
    private $registry = [];

    /**
     * @var array [product id, website id] asked from the registry
     */
    private $asked = [];

    /**
     * @var array product ids the settings were read for, one entry per query
     */
    private $settingsQueries = [];

    /**
     * @var array SKUs the reservations were read for, one entry per query
     */
    private $reservationQueries = [];

    /**
     * @var int
     */
    private $cleaned = 0;

    protected function setUp(): void
    {
        $this->stockItems = [];
        $this->reserved = [];
        $this->registry = [];
        $this->asked = [];
        $this->settingsQueries = [];
        $this->reservationQueries = [];
        $this->cleaned = 0;
    }

    private function stockInfo(bool $managedByConfig = true): StockInfo
    {
        $criteria = $this->createMock(StockItemCriteriaInterface::class);
        $criteria->method('setProductsFilter')->willReturnCallback(function ($productIds) {
            $this->settingsQueries[] = $productIds;
        });
        $criteriaFactory = $this->getMockBuilder(StockItemCriteriaInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $criteriaFactory->method('create')->willReturn($criteria);

        $stockItems = $this->createMock(StockItemRepositoryInterface::class);
        $stockItems->method('getList')->willReturnCallback(function () {
            $items = [];
            foreach ($this->stockItems as $productId => $values) {
                $item = $this->createMock(StockItemInterface::class);
                $item->method('getProductId')->willReturn((string) $productId);
                $item->method('getManageStock')->willReturn($values[0]);
                $item->method('getQty')->willReturn($values[1]);
                $item->method('getMinQty')->willReturn($values[2]);
                $items[] = $item;
            }
            $collection = $this->createMock(StockItemCollectionInterface::class);
            $collection->method('getItems')->willReturn($items);

            return $collection;
        });

        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->method('getStockStatus')->willReturnCallback(function ($productId, $websiteId) {
            $this->asked[] = [$productId, $websiteId];
            if ($this->registry[$productId] instanceof \Exception) {
                throw $this->registry[$productId];
            }
            $status = $this->createMock(StockStatusInterface::class);
            $status->method('getQty')->willReturn($this->registry[$productId]);

            return $status;
        });

        $configuration = $this->createMock(StockConfigurationInterface::class);
        $configuration->method('isQty')->willReturnCallback(function ($type) {
            return in_array($type, ['simple', 'virtual', 'downloadable'], true);
        });
        $configuration->method('getManageStock')->willReturn($managedByConfig ? 1 : 0);

        $storage = $this->createMock(StockRegistryStorage::class);
        $storage->method('clean')->willReturnCallback(function () {
            $this->cleaned++;
        });

        $stockIndex = $this->createMock(StockIndex::class);
        $stockIndex->method('getReserved')->willReturnCallback(function (array $skus) {
            $this->reservationQueries[] = $skus;

            return $this->reserved;
        });

        return new StockInfo($stockItems, $criteriaFactory, $registry, $configuration, $storage, $stockIndex);
    }

    private function row(string $type, string $sku, ?float $quantity): array
    {
        return ['type' => $type, 'sku' => $sku, 'quantity' => $quantity];
    }

    /**
     * @dataProvider cases
     */
    public function testResolve(bool $hasQuantity, bool $managed, float $quantity, string $availability, ?int $units)
    {
        $this->assertSame(
            ['availability' => $availability, 'quantity' => $units],
            $this->stockInfo()->resolve($hasQuantity, $managed, $quantity)
        );
    }

    public static function cases(): array
    {
        return [
            'in stock' => [true, true, 12.0, 'in_stock', 12],
            'one left' => [true, true, 1.0, 'in_stock', 1],
            'sold on backorder' => [true, true, 0.0, 'preorder', null],
            'more ordered than in stock' => [true, true, -3.0, 'preorder', null],
            'stock not managed' => [true, false, 0.0, 'in_stock', null],
            'stock not managed, with a quantity' => [true, false, 5.0, 'in_stock', null],
            'type without quantity' => [false, true, 0.0, 'in_stock', null],
            'sold by weight, less than one unit' => [true, true, 0.4, 'in_stock', null],
            'sold by weight' => [true, true, 2.75, 'in_stock', 2],
        ];
    }

    public function testABatchIsReadWithTwoQueriesAndNothingIsAskedPerProduct()
    {
        $this->stockItems = [
            10 => [true, 50.0, 0.0],
            11 => [false, 0.0, 0.0],
            12 => [true, 1.0, 0.0],
            14 => [true, 9.0, 2.0],
        ];
        // pieces kept for orders that are not shipped yet
        $this->reserved = ['sku-10' => -3.0, 'sku-12' => -2.0];

        $result = $this->stockInfo()->forRows(
            [
                10 => $this->row('simple', 'SKU-10', 50.0),
                11 => $this->row('simple', 'SKU-11', 0.0),
                12 => $this->row('virtual', 'SKU-12', 1.0),
                13 => $this->row('bundle', 'SKU-13', 0.0),
                14 => $this->row('simple', 'SKU-14', 9.0),
            ],
            2
        );

        $this->assertSame(
            [
                10 => ['availability' => 'in_stock', 'quantity' => 47],
                11 => ['availability' => 'in_stock', 'quantity' => null],
                12 => ['availability' => 'preorder', 'quantity' => null],
                13 => ['availability' => 'in_stock', 'quantity' => null],
                // nothing kept for orders, but the last two pieces are below the out of stock threshold
                14 => ['availability' => 'in_stock', 'quantity' => 7],
            ],
            $result
        );
        $this->assertSame([[10, 11, 12, 14]], $this->settingsQueries, 'one query, without the bundle');
        $this->assertSame([['SKU-10', 'SKU-12', 'SKU-14']], $this->reservationQueries, 'only where stock is managed');
        $this->assertSame([], $this->asked, 'the registry costs about ten queries for each product');
        $this->assertSame(0, $this->cleaned);
    }

    public function testProductsAreReadAsTheSelectionLoadsThem()
    {
        $this->stockItems = [10 => [true, 5.0, 0.0]];

        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getSku')->willReturn('SKU-10');
        $product->method('getData')->willReturnCallback(function ($key) {
            return $key === StockIndex::QUANTITY ? '5.0000' : null;
        });

        $this->assertSame(
            [10 => ['availability' => 'in_stock', 'quantity' => 5]],
            $this->stockInfo()->forProducts([10 => $product], 1)
        );
    }

    public function testProductThatCameWithoutQuantityIsAskedFromTheRegistry()
    {
        $this->stockItems = [10 => [true, 50.0, 0.0], 11 => [true, 8.0, 3.0]];
        $this->registry = [10 => 47.0, 11 => new \RuntimeException('no source item')];

        $result = $this->stockInfo()->forRows(
            [10 => $this->row('simple', 'SKU-10', null), 11 => $this->row('simple', 'SKU-11', null)],
            2
        );

        $this->assertSame(
            [
                10 => ['availability' => 'in_stock', 'quantity' => 47],
                // the registry failed: what the stock record says, less the threshold
                11 => ['availability' => 'in_stock', 'quantity' => 5],
            ],
            $result
        );
        $this->assertSame([[10, 2], [11, 2]], $this->asked);
        $this->assertSame([[]], $this->reservationQueries);
        $this->assertSame(1, $this->cleaned, 'what the registry kept is dropped after the batch');
    }

    public function testProductWithoutStockRecordFollowsTheStoreSetting()
    {
        $rows = [10 => $this->row('simple', 'SKU-10', 4.0)];

        $this->assertSame(
            [10 => ['availability' => 'in_stock', 'quantity' => 4]],
            $this->stockInfo(true)->forRows($rows, 1)
        );
        $this->assertSame(
            [10 => ['availability' => 'in_stock', 'quantity' => null]],
            $this->stockInfo(false)->forRows($rows, 1)
        );
    }

    public function testNothingIsReadForTypesWithoutQuantity()
    {
        $this->stockInfo()->forRows(
            [13 => $this->row('bundle', 'B-13', 0.0), 14 => $this->row('grouped', 'G-14', 0.0)],
            1
        );

        $this->assertSame([], $this->settingsQueries);
        $this->assertSame([], $this->asked);
    }
}
