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
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Status as StockStatusResource;
use Magento\Framework\DB\Select;
use Ovebot\Chat\Model\Feed\ProductSelection;
use Ovebot\Chat\Model\ResourceModel\ConfigurableLink;
use Ovebot\Chat\Model\ResourceModel\StockIndex;
use Ovebot\Chat\Model\StoreContext\StoreView;
use PHPUnit\Framework\TestCase;

/**
 * The collections are replaced by a small catalog kept in the test: what matters here is which questions the
 * selection asks and what it makes of the answers.
 */
class ProductSelectionTest extends TestCase
{
    private const STORE_ID = 1;
    private const WEBSITE_ID = 2;

    /**
     * @var array product id => [type, visible]; only products that are enabled, priced and salable
     */
    private $catalog = [];

    /**
     * @var array parent id => child ids
     */
    private $children = [];

    /**
     * @var \stdClass[] what each collection was asked for
     */
    private $queries = [];

    /**
     * @var int collections the quantity of the stock index was added to
     */
    private $quantityAdded = 0;

    protected function setUp(): void
    {
        $this->catalog = [];
        $this->children = [];
        $this->queries = [];
        $this->quantityAdded = 0;
    }

    private function selection(): ProductSelection
    {
        $factory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(function () {
            return $this->collection();
        });

        $stockStatus = $this->createMock(StockStatusResource::class);
        $stockStatus->method('addStockDataToCollection')->willReturnCallback(function ($collection, $inStockOnly) {
            $this->assertTrue($inStockOnly);

            return $collection;
        });

        $visibility = $this->createMock(Visibility::class);
        $visibility->method('getVisibleInSiteIds')->willReturn([2, 3, 4]);

        $links = $this->createMock(ConfigurableLink::class);
        $links->method('getChildIds')->willReturnCallback(function (array $parentIds) {
            return array_intersect_key($this->children, array_flip($parentIds));
        });
        $links->method('getParentIds')->willReturnCallback(function (array $childIds) {
            $parents = [];
            foreach ($this->children as $parentId => $ids) {
                foreach (array_intersect($ids, $childIds) as $childId) {
                    $parents[$childId][] = $parentId;
                }
            }

            return $parents;
        });

        $stockIndex = $this->createMock(StockIndex::class);
        $stockIndex->method('addQuantity')->willReturnCallback(function () {
            $this->quantityAdded++;

            return true;
        });

        return new ProductSelection($factory, $stockStatus, $visibility, $links, $stockIndex);
    }

    private function collection(): Collection
    {
        $query = new \stdClass();
        $query->visibleOnly = false;
        $query->type = null;
        $query->in = null;
        $query->after = 0;
        $query->limit = null;
        $query->store = null;
        $query->website = null;
        $query->priced = false;
        $query->order = null;
        $query->flags = [];
        $this->queries[] = $query;

        $select = $this->createMock(Select::class);
        $select->method('where')->willReturnCallback(function ($condition) use ($query, $select) {
            $query->priced = $query->priced || $condition === 'price_index.min_price > 0';

            return $select;
        });
        $select->method('order')->willReturnCallback(function ($order) use ($query, $select) {
            $query->order = $order;

            return $select;
        });
        $select->method('limit')->willReturnCallback(function ($count) use ($query, $select) {
            $query->limit = $count;

            return $select;
        });

        $collection = $this->createMock(Collection::class);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('setFlag')->willReturnCallback(function ($flag, $value) use ($query, $collection) {
            $query->flags[$flag] = $value;

            return $collection;
        });
        $collection->expects($this->never())->method('setOrder');
        $collection->method('setStoreId')->willReturnCallback(function ($storeId) use ($query, $collection) {
            $query->store = $storeId;

            return $collection;
        });
        $collection->method('addWebsiteFilter')->willReturnCallback(function ($websites) use ($query, $collection) {
            $query->website = $websites;

            return $collection;
        });
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function ($attribute, $condition) use ($query, $collection) {
                if ($attribute === 'visibility') {
                    $this->assertSame(['in' => [2, 3, 4]], $condition);
                    $query->visibleOnly = true;
                }

                return $collection;
            }
        );
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $condition) use ($query, $collection) {
                if ($field === 'type_id') {
                    $query->type = $condition;
                } elseif (isset($condition['in'])) {
                    $query->in = $condition['in'];
                } elseif (isset($condition['gt'])) {
                    $query->after = $condition['gt'];
                }

                return $collection;
            }
        );
        $collection->method('getAllIds')->willReturnCallback(function () use ($query) {
            return array_map('strval', $this->matching($query));
        });
        $collection->method('getSize')->willReturnCallback(function () use ($query) {
            return count($this->matching($query));
        });
        $collection->method('getItems')->willReturnCallback(function () use ($query) {
            $items = [];
            foreach ($this->matching($query) as $productId) {
                $product = $this->createMock(Product::class);
                $product->method('getId')->willReturn((string) $productId);
                $product->method('getTypeId')->willReturn($this->catalog[$productId][0]);
                $items[$productId] = $product;
            }

            return $items;
        });

        return $collection;
    }

    /**
     * Ids of the catalog a query gives, in order
     */
    private function matching(\stdClass $query): array
    {
        $ids = [];
        foreach ($this->catalog as $productId => $product) {
            if (($query->visibleOnly && !$product[1])
                || ($query->type !== null && $product[0] !== $query->type)
                || ($query->in !== null && !in_array($productId, $query->in, true))
                || $productId <= $query->after
            ) {
                continue;
            }
            $ids[] = $productId;
        }
        sort($ids);

        return $query->limit !== null ? array_slice($ids, 0, $query->limit) : $ids;
    }

    private function context(): StoreView
    {
        $context = $this->createMock(StoreView::class);
        $context->method('getStoreId')->willReturn(self::STORE_ID);
        $context->method('getWebsiteId')->willReturn(self::WEBSITE_ID);

        return $context;
    }

    /**
     * 1 simple; 2 configurable with the children 3, 4, 5 (not salable) and 6 (also visible on its own);
     * 7 visible child of a configurable product that is not in the feed; 8 configurable without salable children
     */
    private function sampleCatalog(): void
    {
        $this->catalog = [
            1 => ['simple', true],
            2 => ['configurable', true],
            3 => ['simple', false],
            4 => ['simple', false],
            6 => ['simple', true],
            7 => ['virtual', true],
            8 => ['configurable', true],
        ];
        $this->children = [2 => [3, 4, 5, 6], 8 => [9], 20 => [7]];
    }

    public function testFilterOfTheFeed()
    {
        $this->selection()->collection($this->context());

        $query = $this->queries[0];
        $this->assertSame(self::STORE_ID, $query->store);
        $this->assertSame([self::WEBSITE_ID], $query->website);
        $this->assertTrue($query->visibleOnly);
        $this->assertTrue($query->priced);
    }

    public function testStockIsNotJoinedASecondTimeWhenTheCollectionLoads()
    {
        $this->selection()->collection($this->context());
        $this->selection()->collection($this->context(), false);

        // on the storefront Magento adds the stock at load, unless this flag says it is there already
        $this->assertSame(['has_stock_status_filter' => true], $this->queries[0]->flags);
        $this->assertSame(['has_stock_status_filter' => true], $this->queries[1]->flags);
    }

    public function testQuantityComesWithTheProducts()
    {
        $selection = $this->selection();
        $selection->collection($this->context());
        $selection->collection($this->context(), false);

        $this->assertSame(2, $this->quantityAdded);
    }

    public function testBatchesAreSortedByIdOnTheQueryItself()
    {
        $selection = $this->selection();
        $selection->batch($selection->collection($this->context()), 40, 200);

        $query = $this->queries[0];
        $this->assertSame(40, $query->after);
        $this->assertSame('e.entity_id ASC', $query->order);
        $this->assertSame(200, $query->limit);
    }

    public function testPlanOfABatch()
    {
        $this->sampleCatalog();

        $plan = $this->selection()->plan(
            $this->context(),
            [1 => 'simple', 2 => 'configurable', 6 => 'simple', 7 => 'virtual', 8 => 'configurable']
        );

        $this->assertSame(
            [
                // 6 is sold as a variant of 2; 7 stays, its parent is not in the feed
                'standalone' => [1, 7],
                // 5 does not pass the filter; 8 has nothing to sell
                'variants' => [2 => [3, 4, 6]],
            ],
            $plan
        );
    }

    public function testChildrenAreNotAskedToBeVisibleButParentsAre()
    {
        $this->sampleCatalog();

        $this->selection()->plan($this->context(), [2 => 'configurable', 6 => 'simple']);

        $this->assertCount(2, $this->queries);
        list($parents, $children) = $this->queries;

        $this->assertSame([2], $parents->in);
        $this->assertTrue($parents->visibleOnly);
        $this->assertSame('configurable', $parents->type);

        $this->assertSame([3, 4, 5, 6], $children->in);
        $this->assertFalse($children->visibleOnly);
        $this->assertNull($children->type);
    }

    public function testBatchWithoutConfigurableProductsAsksNothingMore()
    {
        $this->catalog = [1 => ['simple', true], 2 => ['bundle', true]];

        $plan = $this->selection()->plan($this->context(), [1 => 'simple', 2 => 'bundle']);

        $this->assertSame(['standalone' => [1, 2], 'variants' => []], $plan);
        $this->assertSame([], $this->queries);
    }

    /**
     * @dataProvider batchSizes
     */
    public function testCountDoesNotDependOnTheBatchSize(int $batchSize)
    {
        $this->sampleCatalog();

        // 1, 7 on their own, plus 3, 4 and 6 as variants of 2
        $this->assertSame(5, $this->selection()->countItems($this->context(), $batchSize));
    }

    public static function batchSizes(): array
    {
        return ['one by one' => [1], 'two' => [2], 'three' => [3], 'all at once' => [1000]];
    }

    public function testCountOfAnEmptyCatalog()
    {
        $this->assertSame(0, $this->selection()->countItems($this->context(), 1000));
        $this->assertSame(0, $this->selection()->countEnabled($this->context()));
    }

    public function testEnabledProductsAreCountedWithoutTheFilterOfTheFeed()
    {
        $this->sampleCatalog();

        $this->assertSame(7, $this->selection()->countEnabled($this->context()));

        $query = end($this->queries);
        $this->assertFalse($query->visibleOnly);
        $this->assertFalse($query->priced);
    }
}
