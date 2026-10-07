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

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * The other images of the products of a batch, as absolute URLs, in the order of the gallery.
 *
 * Read with the query Magento itself uses for the gallery of a collection (Gallery::createBatchBaseSelect), but
 * not through Collection::addMediaGalleryData(): that one counts the collection first, and on the feed the count
 * runs over every product left in the catalog, once per batch.
 *
 * The main image, the images disabled for the store view and the videos are left out.
 */
class GalleryProvider
{
    /**
     * @var Gallery
     */
    private $gallery;

    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var MediaConfig
     */
    private $mediaConfig;

    /**
     * @var int|null id of the media_gallery attribute, once read
     */
    private $attributeId;

    /**
     * @var string|null column that links a product to its gallery, once read
     */
    private $linkField;

    /**
     * @param Gallery $gallery
     * @param EavConfig $eavConfig
     * @param MetadataPool $metadataPool
     * @param MediaConfig $mediaConfig
     */
    public function __construct(
        Gallery $gallery,
        EavConfig $eavConfig,
        MetadataPool $metadataPool,
        MediaConfig $mediaConfig
    ) {
        $this->gallery = $gallery;
        $this->eavConfig = $eavConfig;
        $this->metadataPool = $metadataPool;
        $this->mediaConfig = $mediaConfig;
    }

    /**
     * The other images of several products, one query for all of them
     *
     * @param Product[] $products by product id
     * @param int $storeId
     * @return array product id => URLs; a product without other images is not in the array
     */
    public function forProducts(array $products, int $storeId): array
    {
        if (!$products) {
            return [];
        }

        $linkField = $this->linkField();
        $links = [];
        foreach ($products as $productId => $product) {
            $link = (int) ($product->getData($linkField) ?: $product->getId());
            $links[$link] = (int) $productId;
        }

        $select = $this->gallery->createBatchBaseSelect($storeId, $this->attributeId())
            ->where('entity.' . $linkField . ' IN (?)', array_keys($links));

        $rows = [];
        foreach ($this->gallery->getConnection()->fetchAll($select) as $row) {
            $link = (int) $row[$linkField];
            if (isset($links[$link])) {
                $rows[$links[$link]][] = $row;
            }
        }

        $urls = [];
        foreach ($rows as $productId => $productRows) {
            $list = $this->urls($productRows, (string) $products[$productId]->getData('image'));
            if ($list) {
                $urls[$productId] = $list;
            }
        }

        return $urls;
    }

    /**
     * URLs of the rows of a gallery, in the order of the rows, without the main image
     *
     * @param array $rows {file, media_type, disabled, position}, as the gallery query gives them
     * @param string $main file of the main image
     * @return string[]
     */
    public function urls(array $rows, string $main): array
    {
        $urls = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $file = isset($row['file']) ? (string) $row['file'] : '';
            $type = isset($row['media_type']) ? (string) $row['media_type'] : 'image';
            if ($file === '' || $file === $main || $type !== 'image' || !empty($row['disabled'])) {
                continue;
            }
            $url = (string) $this->mediaConfig->getMediaUrl($file);
            if (!in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Id of the media_gallery attribute
     *
     * @return int
     */
    private function attributeId(): int
    {
        if ($this->attributeId === null) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, 'media_gallery');
            $this->attributeId = (int) $attribute->getAttributeId();
        }

        return $this->attributeId;
    }

    /**
     * Column that links a product to its gallery: entity_id, on Adobe Commerce row_id
     *
     * @return string
     */
    private function linkField(): string
    {
        if ($this->linkField === null) {
            $this->linkField = (string) $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        }

        return $this->linkField;
    }
}
