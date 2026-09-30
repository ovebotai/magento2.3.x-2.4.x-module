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

use Magento\Framework\Data\OptionSourceInterface;
use Ovebot\Chat\Model\Tracking\TrackingResolver;

/**
 * The tracking sources the merchant can choose from, in Stores > Configuration.
 *
 * The list is the one the order lookup uses: the "finders" of TrackingResolver in di.xml. A module that adds a
 * tracking source declares it once, there.
 */
class TrackingFinders implements OptionSourceInterface
{
    /**
     * @var TrackingResolver
     */
    private $trackingResolver;

    /**
     * @param TrackingResolver $trackingResolver
     */
    public function __construct(TrackingResolver $trackingResolver)
    {
        $this->trackingResolver = $trackingResolver;
    }

    /**
     * Choices of the list
     *
     * @return array [['value' => code, 'label' => label], ...]
     */
    public function toOptionArray()
    {
        $options = [];
        foreach ($this->trackingResolver->getLabels() as $code => $label) {
            $options[] = ['value' => (string) $code, 'label' => $label];
        }

        return $options;
    }
}
