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

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Links between configurable products and their children, read for many products in one query.
 *
 * The links hold the link field of the parent, which is not the entity id on every edition, so the parent is
 * always joined.
 */
class ConfigurableLink
{
    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @param ResourceConnection $resource
     * @param MetadataPool $metadataPool
     */
    public function __construct(ResourceConnection $resource, MetadataPool $metadataPool)
    {
        $this->resource = $resource;
        $this->metadataPool = $metadataPool;
    }

    /**
     * Children of each parent, in the order of their ids
     *
     * @param int[] $parentIds
     * @return array parent id => child ids
     */
    public function getChildIds(array $parentIds): array
    {
        if (!$parentIds) {
            return [];
        }

        $select = $this->select()
            ->where('parent.entity_id IN (?)', $parentIds, \Zend_Db::INT_TYPE)
            ->order(['parent.entity_id ASC', 'link.product_id ASC']);

        $children = [];
        foreach ($this->resource->getConnection()->fetchAll($select) as $row) {
            $children[(int) $row['parent_id']][] = (int) $row['child_id'];
        }

        return $children;
    }

    /**
     * Parents of each child
     *
     * @param int[] $childIds
     * @return array child id => parent ids
     */
    public function getParentIds(array $childIds): array
    {
        if (!$childIds) {
            return [];
        }

        $select = $this->select()->where('link.product_id IN (?)', $childIds, \Zend_Db::INT_TYPE);

        $parents = [];
        foreach ($this->resource->getConnection()->fetchAll($select) as $row) {
            $parents[(int) $row['child_id']][] = (int) $row['parent_id'];
        }

        return $parents;
    }

    /**
     * Ids of the attributes each parent is configured by, in the order of the product page
     *
     * @param int[] $parentIds
     * @return array parent id => attribute ids
     */
    public function getAttributeIds(array $parentIds): array
    {
        if (!$parentIds) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct(true)
            ->from(
                ['super' => $this->resource->getTableName('catalog_product_super_attribute')],
                ['attribute_id', 'position']
            )
            ->join(
                ['parent' => $this->resource->getTableName('catalog_product_entity')],
                'parent.' . $this->getLinkField() . ' = super.product_id',
                ['parent_id' => 'entity_id']
            )
            ->where('parent.entity_id IN (?)', $parentIds, \Zend_Db::INT_TYPE)
            ->order(['parent.entity_id ASC', 'super.position ASC', 'super.attribute_id ASC']);

        $attributes = [];
        foreach ($connection->fetchAll($select) as $row) {
            $parentId = (int) $row['parent_id'];
            $attributeId = (int) $row['attribute_id'];
            if (!isset($attributes[$parentId]) || !in_array($attributeId, $attributes[$parentId], true)) {
                $attributes[$parentId][] = $attributeId;
            }
        }

        return $attributes;
    }

    /**
     * Parent and child of every link; a child with required options cannot be bought as a variant
     *
     * @return Select
     */
    private function select(): Select
    {
        return $this->resource->getConnection()->select()
            ->distinct(true)
            ->from(
                ['link' => $this->resource->getTableName('catalog_product_super_link')],
                ['child_id' => 'product_id']
            )
            ->join(
                ['parent' => $this->resource->getTableName('catalog_product_entity')],
                'parent.' . $this->getLinkField() . ' = link.parent_id',
                ['parent_id' => 'entity_id']
            )
            ->join(
                ['child' => $this->resource->getTableName('catalog_product_entity')],
                'child.entity_id = link.product_id AND child.required_options = 0',
                []
            );
    }

    /**
     * Column of the product table the links point to
     *
     * @return string
     */
    private function getLinkField(): string
    {
        return (string) $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
    }
}
