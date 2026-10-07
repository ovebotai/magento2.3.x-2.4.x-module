<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Ovebot\Chat\Model\Config;
use Ovebot\Chat\Model\Config\Source\GtinAttribute;
use PHPUnit\Framework\TestCase;

class GtinAttributeTest extends TestCase
{
    /**
     * @var array filters put on the collection
     */
    private $filters = [];

    /**
     * @param array $attributes [code => label]
     * @return GtinAttribute
     */
    private function source(array $attributes): GtinAttribute
    {
        $this->filters = [];
        $items = [];
        foreach ($attributes as $code => $label) {
            $attribute = $this->createMock(Attribute::class);
            $attribute->method('getAttributeCode')->willReturn($code);
            $attribute->method('getData')->with('frontend_label')->willReturn($label);
            $items[] = $attribute;
        }

        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) use ($collection) {
            $this->filters[$field] = $condition;

            return $collection;
        });
        $collection->method('setOrder')->willReturnSelf();

        $factory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($collection);

        return new GtinAttribute($factory);
    }

    /**
     * @param array $options
     * @return array the options with the labels as strings
     */
    private function plain(array $options): array
    {
        return array_map(function (array $option) {
            return ['value' => $option['value'], 'label' => (string) $option['label']];
        }, $options);
    }

    public function testAutoAndNoneComeFirstThenTheAttributesOfTheMerchant()
    {
        $source = $this->source(['ean' => 'EAN', 'cod_bare' => '  ', 'gtin' => 'GTIN code']);

        $this->assertSame(
            [
                ['value' => Config::GTIN_AUTO, 'label' => 'Auto-detect'],
                ['value' => '', 'label' => 'None'],
                ['value' => 'ean', 'label' => 'EAN (ean)'],
                ['value' => 'cod_bare', 'label' => 'cod_bare (cod_bare)'],
                ['value' => 'gtin', 'label' => 'GTIN code (gtin)'],
            ],
            $this->plain($source->toOptionArray())
        );
        $this->assertSame(1, $this->filters['is_user_defined']);
        $this->assertSame(['in' => ['text', 'textarea', 'select']], $this->filters['frontend_input']);
    }

    public function testNoAttributesOfTheMerchant()
    {
        $this->assertSame(
            [
                ['value' => Config::GTIN_AUTO, 'label' => 'Auto-detect'],
                ['value' => '', 'label' => 'None'],
            ],
            $this->plain($this->source([])->toOptionArray())
        );
    }
}
