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
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Frontend\AbstractFrontend;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Ovebot\Chat\Model\Feed\AttributeProvider;
use Ovebot\Chat\Model\Util\Text;
use PHPUnit\Framework\TestCase;

class AttributeProviderTest extends TestCase
{
    private const STORE_ID = 1;

    /**
     * Attributes of the catalog: code => [id, label, input, options, visible on the product page]
     */
    private const ATTRIBUTES = [
        'color' => [93, 'Culoare', 'select', [56 => 'Roșu', 57 => 'Negru'], true],
        'material' => [140, 'Material', 'multiselect', [11 => 'Bumbac', 12 => 'Lână', 13 => 'In'], true],
        'eco' => [141, 'Eco', 'boolean', [1 => 'Da', 0 => 'Nu'], true],
        'care' => [142, 'Îngrijire', 'textarea', [], true],
        'manufacturer' => [83, 'Producător', 'select', [7 => 'Brand <b>X</b>'], false],
    ];

    /**
     * @var array attribute code => how many times its options were read
     */
    private $optionReads = [];

    /**
     * @var array attribute code => how many times the frontend model was asked
     */
    private $frontendReads = [];

    /**
     * @var array attribute code => how many times its label was read
     */
    private $labelReads = [];

    /**
     * @var Attribute[] by code, the same object every time, as the EAV configuration gives them
     */
    private $built = [];

    protected function setUp(): void
    {
        $this->optionReads = [];
        $this->frontendReads = [];
        $this->labelReads = [];
        $this->built = [];
    }

    private function provider(): AttributeProvider
    {
        $visible = [];
        foreach (self::ATTRIBUTES as $code => $attribute) {
            if ($attribute[4]) {
                $visible[] = $this->attribute($code);
            }
        }
        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($visible));

        $factory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->expects($this->atMost(1))->method('create')->willReturn($collection);

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(function ($entity, $key) {
            $this->assertSame(Product::ENTITY, $entity);
            foreach (self::ATTRIBUTES as $code => $attribute) {
                if ($key === $code || $key === $attribute[0]) {
                    return $this->attribute($code);
                }
            }

            // what Magento gives for an attribute that does not exist: an object without id
            return $this->createMock(Attribute::class);
        });

        return new AttributeProvider(
            $factory,
            $eavConfig,
            $this->createMock(PriceCurrencyInterface::class),
            new Text()
        );
    }

    private function attribute(string $code): Attribute
    {
        if (isset($this->built[$code])) {
            return $this->built[$code];
        }
        list($id, $label, $input, $options) = self::ATTRIBUTES[$code];

        $source = $this->createMock(AbstractSource::class);
        $source->method('getAllOptions')->willReturnCallback(function () use ($code, $options) {
            $this->optionReads[$code] = isset($this->optionReads[$code]) ? $this->optionReads[$code] + 1 : 1;

            $all = [];
            foreach ($options as $value => $optionLabel) {
                $all[] = ['value' => (string) $value, 'label' => $optionLabel];
            }

            return $all;
        });
        $source->expects($this->never())->method('getOptionText');

        $frontend = $this->createMock(AbstractFrontend::class);
        $frontend->method('getValue')->willReturnCallback(function ($product) use ($code) {
            $this->frontendReads[$code] = isset($this->frontendReads[$code]) ? $this->frontendReads[$code] + 1 : 1;

            return $product->getData($code);
        });

        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getId')->willReturn($id);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getStoreLabel')->willReturnCallback(function ($storeId) use ($code, $label) {
            $this->assertSame(self::STORE_ID, $storeId);
            $this->labelReads[$code] = isset($this->labelReads[$code]) ? $this->labelReads[$code] + 1 : 1;

            return $label;
        });
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getSource')->willReturn($source);
        $attribute->method('getFrontend')->willReturn($frontend);

        return $this->built[$code] = $attribute;
    }

    private function product(array $data): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnCallback(function ($key = '') use ($data) {
            if ($key === '') {
                return $data;
            }

            return isset($data[$key]) ? $data[$key] : null;
        });

        return $product;
    }

    public function testValuesOfAProduct()
    {
        $values = $this->provider()->values(
            $this->product([
                'color' => '56',
                'material' => '11,13',
                'eco' => '0',
                'care' => '<p>Se spală la 30&deg;</p>',
                'manufacturer' => '7',
            ]),
            self::STORE_ID
        );

        $this->assertSame(
            [
                'Culoare' => 'Roșu',
                'Material' => 'Bumbac, In',
                'Eco' => 'Nu',
                'Îngrijire' => 'Se spală la 30°',
            ],
            $values
        );
    }

    public function testAttributesWithoutValueAreLeftOut()
    {
        $values = $this->provider()->values(
            $this->product(['color' => null, 'material' => '', 'care' => '<p></p>', 'eco' => '1']),
            self::STORE_ID
        );

        $this->assertSame(['Eco' => 'Da'], $values);
    }

    public function testValuesKeepTheOrderOfTheAttributesNotOfTheProductData()
    {
        $values = $this->provider()->values(
            $this->product(['sku' => 'A-1', 'eco' => '1', 'name' => 'Tricou', 'color' => '57']),
            self::STORE_ID
        );

        $this->assertSame(['Culoare', 'Eco'], array_keys($values));
    }

    public function testOptionThatNoLongerExistsGivesNoValue()
    {
        $values = $this->provider()->values($this->product(['color' => '999', 'material' => '12,999']), self::STORE_ID);

        $this->assertSame(['Material' => 'Lână'], $values);
    }

    public function testOptionsAreReadOncePerAttributeNotOncePerProduct()
    {
        $provider = $this->provider();

        for ($n = 0; $n < 50; $n++) {
            $provider->values($this->product(['color' => $n % 2 ? '56' : '57', 'material' => '11']), self::STORE_ID);
        }

        $this->assertSame(['color' => 1, 'material' => 1], $this->optionReads);
        $this->assertSame(['color' => 1, 'material' => 1], $this->labelReads, 'eco and care have no value');
        $this->assertSame([], $this->frontendReads, 'a list value is not asked from the frontend model');
    }

    public function testManufacturer()
    {
        $provider = $this->provider();

        $branded = $this->product(['manufacturer' => '7']);

        $this->assertSame('Brand X', $provider->getManufacturer($branded, self::STORE_ID));
        $this->assertSame('', $provider->getManufacturer($this->product([]), self::STORE_ID));
        $this->assertContains('manufacturer', $provider->getSelectCodes());
        $this->assertSame(['color', 'material', 'eco', 'care', 'manufacturer'], $provider->getSelectCodes());
    }

    public function testOptionOfAVariant()
    {
        $provider = $this->provider();

        $this->assertSame(
            ['attribute_id' => 93, 'code' => 'color', 'label' => 'Culoare', 'value_id' => 57, 'value' => 'Negru'],
            $provider->option(93, $this->product(['color' => '57']), self::STORE_ID)
        );
        $this->assertNull($provider->option(93, $this->product([]), self::STORE_ID), 'child without the value');
        $this->assertNull($provider->option(5000, $this->product(['color' => '57']), self::STORE_ID), 'no attribute');
        $this->assertSame('color', $provider->getCode(93));
        $this->assertSame('', $provider->getCode(5000));
    }
}
