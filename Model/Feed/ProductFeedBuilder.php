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
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Ovebot\Chat\Model\StoreContext;
use Ovebot\Chat\Model\StoreContext\StoreView;

/**
 * The product feed, item by item.
 *
 * The products are read in batches that follow each other by id, so the memory used does not grow with the
 * catalog. Which products become items is decided by ProductSelection, the same way the wizard counts them.
 *
 * iterate() must be consumed under the emulation of the storefront (StoreEmulator).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ProductFeedBuilder
{
    public const BATCH = 200;

    /**
     * Configurable products whose children are loaded together
     */
    private const PARENTS_PER_LOAD = 20;

    private const ATTRIBUTES = ['name', 'description', 'short_description', 'image', 'url_key'];

    /**
     * @var StoreContext
     */
    private $storeContext;

    /**
     * @var ProductSelection
     */
    private $selection;

    /**
     * @var VariantProvider
     */
    private $variantProvider;

    /**
     * @var CategoryPathProvider
     */
    private $categoryPaths;

    /**
     * @var AttributeProvider
     */
    private $attributes;

    /**
     * @var StockInfo
     */
    private $stockInfo;

    /**
     * @var PriceResolver
     */
    private $priceResolver;

    /**
     * @var ItemMapper
     */
    private $mapper;

    /**
     * @var MediaConfig
     */
    private $mediaConfig;

    /**
     * @var GtinProvider
     */
    private $gtin;

    /**
     * @var GalleryProvider
     */
    private $galleries;

    /**
     * @var string|null base URL of the storefront, once read
     */
    private $baseUrl;

    /**
     * @param StoreContext $storeContext
     * @param ProductSelection $selection
     * @param VariantProvider $variantProvider
     * @param CategoryPathProvider $categoryPaths
     * @param AttributeProvider $attributes
     * @param StockInfo $stockInfo
     * @param PriceResolver $priceResolver
     * @param ItemMapper $mapper
     * @param MediaConfig $mediaConfig
     * @param GtinProvider $gtin
     * @param GalleryProvider $galleries
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        StoreContext $storeContext,
        ProductSelection $selection,
        VariantProvider $variantProvider,
        CategoryPathProvider $categoryPaths,
        AttributeProvider $attributes,
        StockInfo $stockInfo,
        PriceResolver $priceResolver,
        ItemMapper $mapper,
        MediaConfig $mediaConfig,
        GtinProvider $gtin,
        GalleryProvider $galleries
    ) {
        $this->storeContext = $storeContext;
        $this->selection = $selection;
        $this->variantProvider = $variantProvider;
        $this->categoryPaths = $categoryPaths;
        $this->attributes = $attributes;
        $this->stockInfo = $stockInfo;
        $this->priceResolver = $priceResolver;
        $this->mapper = $mapper;
        $this->mediaConfig = $mediaConfig;
        $this->gtin = $gtin;
        $this->galleries = $galleries;
    }

    /**
     * The items of the feed
     *
     * @param int $afterId id of the product the feed starts after; 0 for the whole feed
     * @return \Generator
     */
    public function iterate(int $afterId = 0): \Generator
    {
        $context = $this->storeContext->get();
        $lastId = max(0, $afterId);

        do {
            $collection = $this->collection($context);
            $this->selection->batch($collection, $lastId, self::BATCH);

            $products = $collection->getItems();
            if ($products) {
                $collection->addCategoryIds();
                foreach ($this->items($context, $products) as $item) {
                    yield $item;
                }
                $lastId = (int) max(array_keys($products));
            }

            $collection->clear();
        } while ($products);
    }

    /**
     * A few items, to look at the feed by hand (the undocumented "limit" and "random" of the feed URL)
     *
     * In order: the first items of the feed. At random: products picked at random, one item each; a configurable
     * product gives one of its variants, so a product with many of them does not fill the sample alone.
     *
     * Like iterate(), it must be consumed under the emulation of the storefront.
     *
     * @param int $limit most items given
     * @param bool $random
     * @return \Generator
     */
    public function sample(int $limit, bool $random): \Generator
    {
        if ($limit <= 0) {
            return;
        }

        $count = 0;
        if (!$random) {
            foreach ($this->iterate() as $item) {
                yield $item;
                if (++$count >= $limit) {
                    return;
                }
            }

            return;
        }

        $context = $this->storeContext->get();

        $collection = $this->collection($context);
        // twice as many: a product may give no item (no variant for sale, a price of zero)
        $collection->getSelect()->orderRand()->limit($limit * 2);
        $products = $collection->getItems();
        if ($products) {
            $collection->addCategoryIds();
        }

        foreach ($products as $productId => $product) {
            $items = iterator_to_array($this->items($context, [$productId => $product]), false);
            if ($items) {
                yield $items[array_rand($items)];
                if (++$count >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * Products of the feed, with what their items are built from
     *
     * @param StoreView $context
     * @return Collection
     */
    private function collection(StoreView $context): Collection
    {
        $collection = $this->selection->collection($context);
        $collection->addAttributeToSelect(self::ATTRIBUTES);
        $collection->addAttributeToSelect($this->attributes->getSelectCodes());
        $collection->addUrlRewrite();

        return $collection;
    }

    /**
     * Items of one batch, in the order of the product ids
     *
     * @param StoreView $context
     * @param Product[] $products by product id
     * @return \Generator
     */
    private function items(StoreView $context, array $products): \Generator
    {
        $types = [];
        $categoryIds = [];
        foreach ($products as $productId => $product) {
            $types[$productId] = (string) $product->getTypeId();
            $categoryIds[$productId] = (array) $product->getCategoryIds();
        }

        $plan = $this->selection->plan($context, $types);
        $standalone = array_flip($plan['standalone']);
        $categories = $this->categoryPaths->forProducts($categoryIds, $context);
        $galleries = $this->galleries->forProducts($products, $context->getStoreId());
        $stock = $this->stockInfo->forProducts(
            array_intersect_key($products, $standalone),
            $context->getWebsiteId()
        );

        // the children of a few parents at a time: a parent can have hundreds of them
        $loads = array_chunk($plan['variants'], self::PARENTS_PER_LOAD, true);
        $loaded = [];
        $variants = [];

        foreach ($products as $productId => $product) {
            $category = isset($categories[$productId]) ? $categories[$productId] : '';

            if (isset($plan['variants'][$productId])) {
                if (!isset($loaded[$productId])) {
                    $loaded = $this->loadOf($loads, $productId);
                    $variants = $this->variantProvider->forParents($context, $loaded);
                }
                $common = $this->common($context, $product, $category, $galleries);
                foreach (isset($variants[$productId]) ? $variants[$productId] : [] as $variant) {
                    $item = $this->variant($context, $common, $product, $variant);
                    if ($item !== null) {
                        yield $item;
                    }
                }
                continue;
            }

            if (isset($standalone[$productId], $stock[$productId])) {
                $common = $this->common($context, $product, $category, $galleries);
                $item = $this->single($context, $common, $product, $stock[$productId]);
                if ($item !== null) {
                    yield $item;
                }
            }
        }
    }

    /**
     * What an item takes from the product page: texts, category, attributes, GTIN, images and URL
     *
     * @param StoreView $context
     * @param Product $product
     * @param string $category
     * @param array $galleries product id => URLs of the other images
     * @return array
     */
    private function common(StoreView $context, Product $product, string $category, array $galleries): array
    {
        $productId = (int) $product->getId();

        return [
            'name' => (string) $product->getName(),
            'description' => (string) $product->getData('description'),
            'short_description' => (string) $product->getData('short_description'),
            'category' => $category,
            'manufacturer' => $this->attributes->getManufacturer($product, $context->getStoreId()),
            'currency' => $this->priceResolver->getCurrencyCode($context),
            'image' => $this->image($product),
            'additional_image_link' => isset($galleries[$productId]) ? $galleries[$productId] : [],
            'url' => $this->url($context, $product),
            'attributes' => $this->attributes->values($product, $context->getStoreId()),
            'gtin' => $this->gtin->get($product, $context->getStoreId()),
            'options' => [],
        ];
    }

    /**
     * Item of a product listed on its own
     *
     * @param StoreView $context
     * @param array $common
     * @param Product $product
     * @param array $stock {availability, quantity}
     * @return array|null null when the price comes out as zero
     */
    private function single(StoreView $context, array $common, Product $product, array $stock): ?array
    {
        $prices = $this->priceResolver->resolve($product, $context);
        if ($prices === null) {
            return null;
        }

        return $this->mapper->map([
            'id' => (string) (int) $product->getId(),
            'sku' => (string) $product->getSku(),
        ] + $stock + $prices + $common);
    }

    /**
     * Item of one child of a configurable product
     *
     * The texts, the category and the URL are the ones of the parent, where the visitor buys the variant. The
     * SKU, the price and the stock are the ones of the child, and so are the images and the GTIN, when the child
     * has them.
     *
     * @param StoreView $context
     * @param array $common of the parent
     * @param Product $parent
     * @param array $variant {product, options, stock, gallery}
     * @return array|null null when the price comes out as zero
     */
    private function variant(StoreView $context, array $common, Product $parent, array $variant): ?array
    {
        /** @var Product $child */
        $child = $variant['product'];

        $prices = $this->priceResolver->resolve($child, $context);
        if ($prices === null) {
            return null;
        }

        $image = $this->image($child);
        $gallery = $variant['gallery'];
        $gtin = $this->gtin->get($child, $context->getStoreId());

        return $this->mapper->map([
            'id' => (int) $parent->getId() . '-' . (int) $child->getId(),
            'sku' => (string) $child->getSku(),
            'image' => $image !== '' ? $image : $common['image'],
            'additional_image_link' => $gallery ? $gallery : $common['additional_image_link'],
            'gtin' => $gtin !== '' ? $gtin : $common['gtin'],
            'options' => $variant['options'],
        ] + $variant['stock'] + $prices + $common);
    }

    /**
     * The group of parents, children included, that a parent is loaded with
     *
     * @param array $loads groups of {parent id: child ids}
     * @param int $parentId
     * @return array parent id => child ids
     */
    private function loadOf(array $loads, int $parentId): array
    {
        foreach ($loads as $load) {
            if (isset($load[$parentId])) {
                return $load;
            }
        }

        return [];
    }

    /**
     * URL of the product page
     *
     * The products come with the path of their page. Putting it after the base URL gives the address Magento
     * builds, without a URL builder made for each product. A product without a path is left to Magento.
     *
     * @param StoreView $context
     * @param Product $product
     * @return string
     */
    private function url(StoreView $context, Product $product): string
    {
        $path = $product->getData('request_path');
        if (!is_string($path) || $path === '') {
            return (string) $product->getProductUrl();
        }

        if ($this->baseUrl === null) {
            $this->baseUrl = rtrim($context->getBaseUrl(), '/') . '/';
        }

        return $this->baseUrl . ltrim($path, '/');
    }

    /**
     * URL of the main image of a product; empty when it has none
     *
     * @param Product $product
     * @return string
     */
    private function image(Product $product): string
    {
        $file = (string) $product->getData('image');

        return $file !== '' && $file !== 'no_selection' ? (string) $this->mediaConfig->getMediaUrl($file) : '';
    }
}
