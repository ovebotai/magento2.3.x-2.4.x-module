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

use Ovebot\Chat\Model\ResourceModel\ConfigurableLink;
use Ovebot\Chat\Model\StoreContext\StoreView;

/**
 * Children of configurable products, loaded for several parents at once, each with the options it is chosen by
 * and with its own stock.
 */
class VariantProvider
{
    /**
     * Read from every child besides the attributes the parents are configured by
     */
    private const ATTRIBUTES = ['image'];

    /**
     * @var ProductSelection
     */
    private $selection;

    /**
     * @var ConfigurableLink
     */
    private $links;

    /**
     * @var AttributeProvider
     */
    private $attributes;

    /**
     * @var StockInfo
     */
    private $stockInfo;

    /**
     * @param ProductSelection $selection
     * @param ConfigurableLink $links
     * @param AttributeProvider $attributes
     * @param StockInfo $stockInfo
     */
    public function __construct(
        ProductSelection $selection,
        ConfigurableLink $links,
        AttributeProvider $attributes,
        StockInfo $stockInfo
    ) {
        $this->selection = $selection;
        $this->links = $links;
        $this->attributes = $attributes;
        $this->stockInfo = $stockInfo;
    }

    /**
     * Variants of configurable products
     *
     * @param StoreView $context
     * @param array $childIds parent id => ids of the children that pass the filter of the feed
     * @return array parent id => [{product: Product, options: [...], stock: {availability, quantity}}, ...]
     */
    public function forParents(StoreView $context, array $childIds): array
    {
        if (!$childIds) {
            return [];
        }

        $attributeIds = $this->links->getAttributeIds(array_keys($childIds));
        $children = $this->load($context, array_merge(...array_values($childIds)), $attributeIds);

        $stock = $this->stockInfo->forProducts($children, $context->getWebsiteId());

        $variants = [];
        foreach ($childIds as $parentId => $ids) {
            foreach ($ids as $childId) {
                if (!isset($children[$childId], $stock[$childId])) {
                    continue;
                }

                $options = [];
                foreach (isset($attributeIds[$parentId]) ? $attributeIds[$parentId] : [] as $attributeId) {
                    $option = $this->attributes->option($attributeId, $children[$childId], $context->getStoreId());
                    if ($option !== null) {
                        $options[] = $option;
                    }
                }

                $variants[$parentId][] = [
                    'product' => $children[$childId],
                    'options' => $options,
                    'stock' => $stock[$childId],
                ];
            }
        }

        return $variants;
    }

    /**
     * Load the children with their prices and the values of the configurable attributes
     *
     * @param StoreView $context
     * @param int[] $childIds
     * @param array $attributeIds parent id => attribute ids
     * @return \Magento\Catalog\Model\Product[] by product id
     */
    private function load(StoreView $context, array $childIds, array $attributeIds): array
    {
        $codes = self::ATTRIBUTES;
        foreach ($attributeIds ? array_unique(array_merge(...array_values($attributeIds))) : [] as $attributeId) {
            $code = $this->attributes->getCode((int) $attributeId);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        $collection = $this->selection->collection($context, false);
        $collection->addAttributeToSelect(array_values(array_unique($codes)));
        $collection->addFieldToFilter('entity_id', ['in' => array_values(array_unique($childIds))]);

        $children = [];
        foreach ($collection->getItems() as $child) {
            $children[(int) $child->getId()] = $child;
        }

        return $children;
    }
}
