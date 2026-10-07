<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;
use Ovebot\Chat\Model\Config;

/**
 * The product attribute the feed sends as "gtin", in Stores > Configuration.
 *
 * Besides "Auto-detect" (the default: the first attribute with a known code, see Feed\GtinProvider) and "None",
 * the list holds the attributes a merchant adds for a barcode: the user-defined ones of the kinds text, textarea
 * and select.
 */
class GtinAttribute implements OptionSourceInterface
{
    /**
     * Kinds of attribute a barcode is kept in
     */
    private const INPUTS = ['text', 'textarea', 'select'];

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(CollectionFactory $collectionFactory)
    {
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * Choices of the list
     *
     * @return array [['value' => code, 'label' => label], ...]
     */
    public function toOptionArray()
    {
        $options = [
            ['value' => Config::GTIN_AUTO, 'label' => __('Auto-detect')],
            ['value' => '', 'label' => __('None')],
        ];

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('is_user_defined', 1);
        $collection->addFieldToFilter('frontend_input', ['in' => self::INPUTS]);
        $collection->setOrder('frontend_label', 'ASC');

        foreach ($collection as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            $label = trim((string) $attribute->getData('frontend_label'));

            $options[] = [
                'value' => $code,
                'label' => ($label !== '' ? $label : $code) . ' (' . $code . ')',
            ];
        }

        return $options;
    }
}
