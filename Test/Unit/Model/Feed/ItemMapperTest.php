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

use Ovebot\Chat\Model\Feed\ItemMapper;
use Ovebot\Chat\Model\Util\Text;
use PHPUnit\Framework\TestCase;

class ItemMapperTest extends TestCase
{
    private function data(array $overrides = []): array
    {
        return $overrides + [
            'id' => '14',
            'sku' => 'TRICOU-BBC',
            'name' => 'Tricou <b>bumbac</b>',
            'description' => '<p>Tricou din bumbac.</p><p>Se spală la 30&deg;.</p>',
            'short_description' => 'Scurt',
            'category' => 'Îmbrăcăminte > Tricouri',
            'manufacturer' => 'Brand',
            'availability' => 'in_stock',
            'quantity' => 12,
            'price' => 99.9,
            'special' => 79.9,
            'currency' => 'RON',
            'image' => 'https://shop.test/media/catalog/product/t/r/tricou.jpg',
            'url' => 'https://shop.test/tricou-bumbac.html',
            'attributes' => ['Material' => 'Bumbac'],
            'gtin' => '',
            'additional_image_link' => [],
            'options' => [],
        ];
    }

    public function testItemOfAProductListedOnItsOwn()
    {
        $item = (new ItemMapper(new Text()))->map($this->data());

        $this->assertSame(
            [
                'ref', 'name', 'description', 'category', 'manufacturer', 'availability', 'quantity', 'price',
                'special', 'currency', 'image', 'url', 'attributes', 'sku',
            ],
            array_keys($item)
        );
        $this->assertSame('14', $item['ref']);
        $this->assertSame('TRICOU-BBC', $item['sku']);
        $this->assertSame('Tricou bumbac', $item['name']);
        $this->assertSame("Tricou din bumbac.\nSe spală la 30°.", $item['description']);
        $this->assertSame('Îmbrăcăminte > Tricouri', $item['category']);
        $this->assertSame('Brand', $item['manufacturer']);
        $this->assertSame('in_stock', $item['availability']);
        $this->assertSame(12, $item['quantity']);
        $this->assertSame(99.9, $item['price']);
        $this->assertSame(79.9, $item['special']);
        $this->assertSame('RON', $item['currency']);
        $this->assertSame('https://shop.test/tricou-bumbac.html', $item['url']);
        $this->assertSame(['Material' => 'Bumbac'], (array) $item['attributes']);
    }

    public function testVariantGetsTheOptionsInNameAttributesAndUrl()
    {
        $item = (new ItemMapper(new Text()))->map($this->data([
            'id' => '14-31',
            'sku' => 'TRICOU-BBC-ROSU-M',
            'url' => 'https://shop.test/tricou-bumbac.html#old',
            'options' => [
                ['attribute_id' => 93, 'label' => 'Culoare', 'value_id' => 56, 'value' => 'Roșu'],
                ['attribute_id' => 144, 'label' => 'Mărime', 'value_id' => 167, 'value' => 'M'],
            ],
        ]));

        $this->assertSame('14-31', $item['ref']);
        $this->assertSame('TRICOU-BBC-ROSU-M', $item['sku']);
        $this->assertSame('Tricou bumbac - Roșu, M', $item['name']);
        $this->assertSame('https://shop.test/tricou-bumbac.html#93=56&144=167', $item['url']);
        $this->assertSame(
            ['Material' => 'Bumbac', 'Culoare' => 'Roșu', 'Mărime' => 'M'],
            (array) $item['attributes']
        );
    }

    public function testSkuIsSentExactlyAsStoredAndLeftOutWhenEmpty()
    {
        $mapper = new ItemMapper(new Text());

        $this->assertSame(' Ab-12 /x ', $mapper->map($this->data(['sku' => ' Ab-12 /x ']))['sku']);
        $this->assertArrayNotHasKey('sku', $mapper->map($this->data(['sku' => ''])));
    }

    public function testReferenceIsTheIdWhateverTheSku()
    {
        $mapper = new ItemMapper(new Text());

        $this->assertSame('14', $mapper->map($this->data(['sku' => '']))['ref']);
        $this->assertSame('15', $mapper->map($this->data(['id' => '15', 'sku' => 'SKU-1']))['ref']);
        $this->assertSame('16', $mapper->map($this->data(['id' => '16', 'sku' => 'SKU-1']))['ref']);
        $this->assertSame('20-21', $mapper->map($this->data(['id' => '20-21', 'sku' => '30']))['ref']);
    }

    public function testGtinIsTrimmedAndLeftOutWhenEmpty()
    {
        $mapper = new ItemMapper(new Text());

        $this->assertSame('5901234123457', $mapper->map($this->data(['gtin' => ' 5901234123457 ']))['gtin']);
        $this->assertArrayNotHasKey('gtin', $mapper->map($this->data(['gtin' => '  '])));
        $this->assertArrayNotHasKey('gtin', $mapper->map($this->data(['gtin' => null])));
    }

    public function testAdditionalImagesKeepTheirOrderAndAreLeftOutWhenThereAreNone()
    {
        $mapper = new ItemMapper(new Text());
        $urls = ['https://shop.test/media/catalog/product/t/r/tricou-2.jpg', 'https://shop.test/media/t-3.jpg'];

        $item = $mapper->map($this->data(['gtin' => '123', 'additional_image_link' => $urls]));
        $this->assertSame($urls, $item['additional_image_link']);
        $this->assertSame(['attributes', 'sku', 'gtin', 'additional_image_link'], array_slice(array_keys($item), -4));

        foreach ([[], 'x', [1]] as $none) {
            $item = $mapper->map($this->data(['additional_image_link' => $none]));
            $this->assertArrayNotHasKey('additional_image_link', $item);
        }
    }

    public function testShortDescriptionIsUsedWhenTheLongOneHasNoText()
    {
        $item = (new ItemMapper(new Text()))->map($this->data([
            'description' => '<div data-content-type="row"> {{widget type="X"}} </div>',
            'short_description' => '<p>Text scurt</p>',
        ]));

        $this->assertSame('Text scurt', $item['description']);
    }

    /**
     * @dataProvider specials
     */
    public function testSpecialOnlyWhenLowerThanThePrice($special, ?float $expected)
    {
        $item = (new ItemMapper(new Text()))->map($this->data(['price' => 100.0, 'special' => $special]));

        $this->assertSame($expected, $item['special']);
    }

    public static function specials(): array
    {
        return [
            'lower' => [80.0, 80.0],
            'equal' => [100.0, null],
            'higher' => [120.0, null],
            'zero' => [0.0, null],
            'not set' => [null, null],
        ];
    }

    public function testMissingValuesBecomeNullAndAttributesStayAnObject()
    {
        $item = (new ItemMapper(new Text()))->map($this->data([
            'category' => '',
            'manufacturer' => '',
            'quantity' => null,
            'image' => '',
            'attributes' => [],
            'availability' => 'preorder',
        ]));

        $this->assertNull($item['category']);
        $this->assertNull($item['manufacturer']);
        $this->assertNull($item['quantity']);
        $this->assertNull($item['image']);
        $this->assertSame('preorder', $item['availability']);
        $this->assertSame('{}', json_encode($item['attributes']));
    }
}
