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

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Status as StockStatusResource;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Ovebot\Chat\Model\ResourceModel\ConfigurableLink;
use Ovebot\Chat\Model\ResourceModel\StockIndex;
use Ovebot\Chat\Model\StoreContext\StoreView;

/**
 * Decides which products become feed items. The feed and the count of the wizard both ask here, so the number
 * the wizard promises is the number of items the feed delivers.
 *
 * A product is in the feed when it is enabled in the store view, assigned to its website, priced and salable.
 * The price and the stock are read from the indexes. Must run under the emulation of the store view: the stock
 * of the website is found through the current store.
 */
class ProductSelection
{
    /**
     * Prices of the visitor who is not logged in
     */
    public const CUSTOMER_GROUP_ID = 0;

    private const ID_CHUNK = 1000;

    /**
     * Flag read by Magento_CatalogInventory before it adds the stock to a collection
     */
    private const STOCK_FLAG = 'has_stock_status_filter';

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var StockStatusResource
     */
    private $stockStatus;

    /**
     * @var Visibility
     */
    private $visibility;

    /**
     * @var ConfigurableLink
     */
    private $links;

    /**
     * @var StockIndex
     */
    private $stockIndex;

    /**
     * @param CollectionFactory $collectionFactory
     * @param StockStatusResource $stockStatus
     * @param Visibility $visibility
     * @param ConfigurableLink $links
     * @param StockIndex $stockIndex
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        StockStatusResource $stockStatus,
        Visibility $visibility,
        ConfigurableLink $links,
        StockIndex $stockIndex
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->stockStatus = $stockStatus;
        $this->visibility = $visibility;
        $this->links = $links;
        $this->stockIndex = $stockIndex;
    }

    /**
     * Products that pass the filter of the feed
     *
     * @param StoreView $context
     * @param bool $visibleOnly false for the children of a configurable product, which are usually not visible
     * @return Collection
     */
    public function collection(StoreView $context, bool $visibleOnly = true): Collection
    {
        $collection = $this->enabled($context);
        if ($visibleOnly) {
            $collection->addAttributeToFilter('visibility', ['in' => $this->visibility->getVisibleInSiteIds()]);
        }

        $collection->addPriceData(self::CUSTOMER_GROUP_ID, $context->getWebsiteId());
        // the lowest price a product can be bought for; it also covers the types that have no price of their own
        $collection->getSelect()->where('price_index.min_price > 0');

        $this->stockStatus->addStockDataToCollection($collection, true);
        // On the storefront Magento joins the stock by itself when a product collection loads, and only filters
        // when out of stock products are hidden. The flag tells it the stock is already there.
        $collection->setFlag(self::STOCK_FLAG, true);
        // the quantity comes with the products, in the same query
        $this->stockIndex->addQuantity($collection);

        return $collection;
    }

    /**
     * Number of enabled products of the shop, whether they reach the feed or not
     *
     * @param StoreView $context
     * @return int
     */
    public function countEnabled(StoreView $context): int
    {
        return (int) $this->enabled($context)->getSize();
    }

    /**
     * Number of feed items the catalog gives
     *
     * @param StoreView $context
     * @param int $batchSize products read at a time
     * @return int
     */
    public function countItems(StoreView $context, int $batchSize): int
    {
        $count = 0;
        $lastId = 0;

        do {
            // no attributes are read: the type of the product is all the plan needs
            $collection = $this->batch($this->collection($context), $lastId, $batchSize);

            $types = [];
            foreach ($collection->getItems() as $product) {
                $types[(int) $product->getId()] = (string) $product->getTypeId();
            }
            $collection->clear();

            if ($types) {
                $plan = $this->plan($context, $types);
                $count += count($plan['standalone']) + array_sum(array_map('count', $plan['variants']));
                $lastId = (int) max(array_keys($types));
            }
        } while ($types);

        return $count;
    }

    /**
     * Limit a collection to the batch that follows a product id
     *
     * The limit is set on the query: a page size would cost one count query for every batch.
     *
     * @param Collection $collection
     * @param int $afterId
     * @param int $size
     * @return Collection
     */
    public function batch(Collection $collection, int $afterId, int $size): Collection
    {
        $collection->addFieldToFilter('entity_id', ['gt' => $afterId]);
        // set on the query as well: setOrder() lets other modules put their own sorting in front, and the
        // batches follow each other only when the id is the first thing the products are sorted by
        $collection->getSelect()->order('e.entity_id ' . Collection::SORT_ORDER_ASC)->limit($size);

        return $collection;
    }

    /**
     * What a batch of products gives to the feed
     *
     * A configurable product gives one item for each child that passes the filter. A child is not listed a second
     * time on its own, also when it is visible individually.
     *
     * @param StoreView $context
     * @param array $types product id => product type, the products of the batch
     * @return array {standalone: product ids, variants: {parent id: child ids}}
     */
    public function plan(StoreView $context, array $types): array
    {
        $parents = [];
        $others = [];
        foreach ($types as $productId => $type) {
            if ($type === Configurable::TYPE_CODE) {
                $parents[] = (int) $productId;
            } else {
                $others[] = (int) $productId;
            }
        }

        return [
            'standalone' => $this->standalone($context, $others),
            'variants' => $this->variants($context, $parents),
        ];
    }

    /**
     * Enabled products of the website, as the storefront of the shop has them
     *
     * @param StoreView $context
     * @return Collection
     */
    private function enabled(StoreView $context): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($context->getStoreId());
        $collection->addWebsiteFilter([$context->getWebsiteId()]);
        // read in the store view: a product can be disabled for one language only
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);

        return $collection;
    }

    /**
     * Children of each configurable product that pass the filter
     *
     * @param StoreView $context
     * @param int[] $parentIds
     * @return array parent id => child ids
     */
    private function variants(StoreView $context, array $parentIds): array
    {
        $children = $this->links->getChildIds($parentIds);
        if (!$children) {
            return [];
        }

        $allowed = array_flip($this->filterIds($context, array_merge(...array_values($children)), false, false));

        $variants = [];
        foreach ($children as $parentId => $childIds) {
            $kept = [];
            foreach ($childIds as $childId) {
                if (isset($allowed[$childId])) {
                    $kept[] = $childId;
                }
            }
            if ($kept) {
                $variants[$parentId] = $kept;
            }
        }

        return $variants;
    }

    /**
     * Products listed on their own: the ones that are not a variant of a configurable product of the feed
     *
     * @param StoreView $context
     * @param int[] $productIds
     * @return int[]
     */
    private function standalone(StoreView $context, array $productIds): array
    {
        $parents = $this->links->getParentIds($productIds);
        if (!$parents) {
            return $productIds;
        }

        $inFeed = array_flip($this->filterIds($context, array_merge(...array_values($parents)), true, true));

        $standalone = [];
        foreach ($productIds as $productId) {
            $isVariant = false;
            foreach (isset($parents[$productId]) ? $parents[$productId] : [] as $parentId) {
                if (isset($inFeed[$parentId])) {
                    $isVariant = true;
                    break;
                }
            }
            if (!$isVariant) {
                $standalone[] = $productId;
            }
        }

        return $standalone;
    }

    /**
     * The given products that pass the filter of the feed
     *
     * @param StoreView $context
     * @param int[] $productIds
     * @param bool $visibleOnly
     * @param bool $configurableOnly
     * @return int[]
     */
    private function filterIds(StoreView $context, array $productIds, bool $visibleOnly, bool $configurableOnly): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        $passed = [];
        foreach (array_chunk($productIds, self::ID_CHUNK) as $chunk) {
            $collection = $this->collection($context, $visibleOnly);
            $collection->addFieldToFilter('entity_id', ['in' => $chunk]);
            if ($configurableOnly) {
                $collection->addFieldToFilter('type_id', Configurable::TYPE_CODE);
            }
            foreach ($collection->getAllIds() as $productId) {
                $passed[] = (int) $productId;
            }
        }

        return $passed;
    }
}
