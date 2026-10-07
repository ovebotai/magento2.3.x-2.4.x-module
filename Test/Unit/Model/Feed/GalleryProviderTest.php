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
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Ovebot\Chat\Model\Feed\GalleryProvider;
use PHPUnit\Framework\TestCase;

class GalleryProviderTest extends TestCase
{
    /**
     * @var array rows the gallery query answers with
     */
    private $rows = [];

    /**
     * @var array what the query was built with: [store id, attribute id, where]
     */
    private $query = [];

    /**
     * @var int queries run
     */
    private $queries = 0;

    protected function setUp(): void
    {
        $this->rows = [];
        $this->query = [];
        $this->queries = 0;
    }

    private function provider(): GalleryProvider
    {
        $select = $this->createMock(Select::class);
        $select->method('where')->willReturnCallback(function ($condition, $value) use ($select) {
            $this->query[] = [$condition, $value];

            return $select;
        });

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchAll')->willReturnCallback(function () {
            $this->queries++;

            return $this->rows;
        });

        $gallery = $this->createMock(Gallery::class);
        $gallery->method('createBatchBaseSelect')->willReturnCallback(function ($storeId, $attributeId) use ($select) {
            $this->query[] = [$storeId, $attributeId];

            return $select;
        });
        $gallery->method('getConnection')->willReturn($connection);

        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getAttributeId')->willReturn('90');
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->with(Product::ENTITY, 'media_gallery')->willReturn($attribute);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        $mediaConfig = $this->createMock(MediaConfig::class);
        $mediaConfig->method('getMediaUrl')->willReturnCallback(function ($file) {
            return 'https://shop.test/media/catalog/product' . $file;
        });

        return new GalleryProvider($gallery, $eavConfig, $metadataPool, $mediaConfig);
    }

    /**
     * @param int $id
     * @param string $image main image
     * @return Product
     */
    private function product(int $id, string $image): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn((string) $id);
        $product->method('getData')->willReturnCallback(function ($key = '') use ($id, $image) {
            $data = ['entity_id' => (string) $id, 'image' => $image];

            return isset($data[$key]) ? $data[$key] : null;
        });

        return $product;
    }

    public function testNothingIsAskedForNoProducts()
    {
        $this->assertSame([], $this->provider()->forProducts([], 1));
        $this->assertSame(0, $this->queries);
    }

    public function testOneQueryForTheBatchWithTheUrlsInTheOrderOfTheGallery()
    {
        $row = function (string $id, string $file, string $type = 'image', string $disabled = '0'): array {
            return ['entity_id' => $id, 'file' => $file, 'media_type' => $type, 'disabled' => $disabled];
        };
        $this->rows = [
            $row('7', '/a/b.jpg'),
            $row('7', '/a/main.jpg'),
            $row('7', '/a/c.jpg'),
            $row('7', '/a/hidden.jpg', 'image', '1'),
            $row('7', '/a/clip.jpg', 'external-video'),
            $row('8', '/a/main.jpg'),
            // a product that is not in the batch
            $row('9', '/x.jpg'),
        ];
        $products = [7 => $this->product(7, '/a/main.jpg'), 8 => $this->product(8, '/a/main.jpg')];

        $urls = $this->provider()->forProducts($products, 3);

        $this->assertSame(
            [7 => [
                'https://shop.test/media/catalog/product/a/b.jpg',
                'https://shop.test/media/catalog/product/a/c.jpg',
            ]],
            $urls
        );
        $this->assertSame(1, $this->queries);
        $this->assertSame([[3, 90], ['entity.entity_id IN (?)', [7, 8]]], $this->query);
    }

    public function testUrlsSkipDuplicatesAndBrokenRows()
    {
        $urls = $this->provider()->urls([
            ['file' => '/d.jpg', 'media_type' => 'image'],
            ['file' => '/d.jpg', 'media_type' => 'image'],
            ['file' => '', 'media_type' => 'image'],
            'not a row',
            ['media_type' => 'image'],
        ], '/main.jpg');

        $this->assertSame(['https://shop.test/media/catalog/product/d.jpg'], $urls);
    }
}
