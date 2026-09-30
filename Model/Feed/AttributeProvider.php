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
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Phrase;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Ovebot\Chat\Model\Util\Text;

/**
 * Attributes of the feed items: the ones the product page lists under "More Information", that is the
 * attributes with "Visible on Catalog Pages on Storefront" set to Yes. Labels and values are read in the
 * language of the storefront.
 */
class AttributeProvider
{
    public const MANUFACTURER = 'manufacturer';

    /**
     * Kinds of attribute whose value is the id of an option
     */
    private const INPUTS_WITH_OPTIONS = ['select', 'multiselect', 'boolean'];

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var Text
     */
    private $text;

    /**
     * @var string[]|null codes of the visible attributes, once read
     */
    private $visibleCodes;

    /**
     * @var array|null code of a visible attribute => its position, once read
     */
    private $visibleIndex;

    /**
     * @var array attribute code or id => attribute, or false when there is none
     */
    private $attributes = [];

    /**
     * @var array attribute code => [option id => label], for the attributes read so far
     */
    private $options = [];

    /**
     * @var string[] attribute code => label, for the attributes read so far
     */
    private $labels = [];

    /**
     * @param CollectionFactory $collectionFactory
     * @param EavConfig $eavConfig
     * @param PriceCurrencyInterface $priceCurrency
     * @param Text $text
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        EavConfig $eavConfig,
        PriceCurrencyInterface $priceCurrency,
        Text $text
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->eavConfig = $eavConfig;
        $this->priceCurrency = $priceCurrency;
        $this->text = $text;
    }

    /**
     * Codes of the attributes the feed reads from every product
     *
     * @return string[]
     */
    public function getSelectCodes(): array
    {
        $codes = $this->getVisibleCodes();
        if ($this->attribute(self::MANUFACTURER, 0) !== null) {
            $codes[] = self::MANUFACTURER;
        }

        return array_values(array_unique($codes));
    }

    /**
     * Visible attributes of a product that have a value
     *
     * @param Product $product
     * @param int $storeId
     * @return array label => value
     */
    public function values(Product $product, int $storeId): array
    {
        if ($this->visibleIndex === null) {
            $this->visibleIndex = array_flip($this->getVisibleCodes());
        }

        // A catalog can have hundreds of visible attributes and a product values for a few of them: only
        // the ones the product came with are looked at, in the order of the attributes.
        $values = [];
        foreach (array_intersect_key($this->visibleIndex, (array) $product->getData()) as $code => $position) {
            $code = (string) $code;
            $raw = $product->getData($code);
            if ($raw === null || $raw === '' || $raw === false) {
                continue;
            }

            $attribute = $this->attribute($code, $storeId);
            if ($attribute === null) {
                continue;
            }

            $label = $this->label($attribute, $storeId);
            $value = $this->value($attribute, $product);
            if ($label !== '' && $value !== '') {
                $values[$label] = $value;
            }
        }

        return $values;
    }

    /**
     * Manufacturer of a product; empty when there is none
     *
     * @param Product $product
     * @param int $storeId
     * @return string
     */
    public function getManufacturer(Product $product, int $storeId): string
    {
        $attribute = $this->attribute(self::MANUFACTURER, $storeId);

        return $attribute !== null ? $this->text->line($this->value($attribute, $product)) : '';
    }

    /**
     * One option a configurable product is chosen by, as a child has it
     *
     * @param int $attributeId
     * @param Product $child
     * @param int $storeId
     * @return array|null {attribute_id: int, code: string, label: string, value_id: int, value: string}
     */
    public function option(int $attributeId, Product $child, int $storeId): ?array
    {
        $attribute = $this->attribute($attributeId, $storeId);
        if ($attribute === null) {
            return null;
        }

        $valueId = (int) $child->getData($attribute->getAttributeCode());
        $label = $this->label($attribute, $storeId);
        $value = $this->text->line($this->value($attribute, $child));
        if ($valueId <= 0 || $label === '' || $value === '') {
            return null;
        }

        return [
            'attribute_id' => (int) $attribute->getId(),
            'code' => (string) $attribute->getAttributeCode(),
            'label' => $label,
            'value_id' => $valueId,
            'value' => $value,
        ];
    }

    /**
     * Code of a product attribute; empty when the attribute does not exist
     *
     * @param int $attributeId
     * @return string
     */
    public function getCode(int $attributeId): string
    {
        $attribute = $this->attribute($attributeId, 0);

        return $attribute !== null ? (string) $attribute->getAttributeCode() : '';
    }

    /**
     * Codes of the attributes shown on the product page
     *
     * @return string[]
     */
    private function getVisibleCodes(): array
    {
        if ($this->visibleCodes === null) {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('additional_table.is_visible_on_front', 1);
            $collection->setOrder('main_table.attribute_id', 'ASC');

            $this->visibleCodes = [];
            foreach ($collection as $attribute) {
                $this->visibleCodes[] = (string) $attribute->getAttributeCode();
            }
        }

        return $this->visibleCodes;
    }

    /**
     * Value of an attribute as the product page prints it, as plain text
     *
     * @param AbstractAttribute $attribute
     * @param Product $product
     * @return string
     */
    private function value(AbstractAttribute $attribute, Product $product): string
    {
        $raw = $product->getData($attribute->getAttributeCode());
        if ($raw === null || $raw === '' || $raw === false) {
            return '';
        }

        if (in_array($attribute->getFrontendInput(), self::INPUTS_WITH_OPTIONS, true)) {
            return $this->labels($attribute, $raw);
        }

        $value = $attribute->getFrontend()->getValue($product);
        if ($value instanceof Phrase) {
            $value = (string) $value;
        } elseif ($attribute->getFrontendInput() === 'price' && is_scalar($value)) {
            $value = $this->priceCurrency->convertAndFormat($value, false);
        } elseif (is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_scalar'));
        }

        return is_scalar($value) && !is_bool($value) ? $this->text->plain((string) $value) : '';
    }

    /**
     * Label of an attribute on the storefront, read once
     *
     * @param AbstractAttribute $attribute
     * @param int $storeId
     * @return string
     */
    private function label(AbstractAttribute $attribute, int $storeId): string
    {
        $code = (string) $attribute->getAttributeCode();
        if (!isset($this->labels[$code])) {
            $this->labels[$code] = $this->text->line((string) $attribute->getStoreLabel($storeId));
        }

        return $this->labels[$code];
    }

    /**
     * Labels of the options a product has for an attribute, several joined by a comma
     *
     * Magento reads the label of an option with one query each time it is asked. A feed asks for every
     * attribute of every product, so the options of an attribute are read once and kept.
     *
     * @param AbstractAttribute $attribute
     * @param mixed $raw option id, or several: as an array or separated by commas
     * @return string
     */
    private function labels(AbstractAttribute $attribute, $raw): string
    {
        $code = (string) $attribute->getAttributeCode();
        if (!isset($this->options[$code])) {
            $this->options[$code] = $this->options($attribute);
        }

        $labels = [];
        foreach (is_array($raw) ? $raw : explode(',', (string) $raw) as $optionId) {
            $optionId = is_scalar($optionId) ? trim((string) $optionId) : '';
            if (isset($this->options[$code][$optionId])) {
                $labels[] = $this->options[$code][$optionId];
            }
        }

        return implode(', ', $labels);
    }

    /**
     * Options of an attribute, in the language of the storefront
     *
     * @param AbstractAttribute $attribute
     * @return string[] option id => label
     */
    private function options(AbstractAttribute $attribute): array
    {
        try {
            $all = $attribute->getSource()->getAllOptions(false);
        } catch (\Exception $e) {
            return [];
        }

        $options = [];
        foreach (is_array($all) ? $all : [] as $option) {
            if (isset($option['value'], $option['label']) && is_scalar($option['value'])) {
                $label = $this->text->line((string) $option['label']);
                if ($label !== '') {
                    $options[(string) $option['value']] = $label;
                }
            }
        }

        return $options;
    }

    /**
     * Product attribute by code or id, set to the language of the storefront
     *
     * @param string|int $key attribute code or attribute id
     * @param int $storeId
     * @return AbstractAttribute|null
     */
    private function attribute($key, int $storeId): ?AbstractAttribute
    {
        if (!array_key_exists($key, $this->attributes)) {
            try {
                $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $key);
            } catch (\Exception $e) {
                $attribute = null;
            }
            $this->attributes[$key] = $attribute && $attribute->getId() ? $attribute : false;
        }

        $attribute = $this->attributes[$key];
        if (!$attribute) {
            return null;
        }
        if ($storeId > 0) {
            // the labels of the options are read for the store the attribute holds
            $attribute->setStoreId($storeId);
        }

        return $attribute;
    }
}
