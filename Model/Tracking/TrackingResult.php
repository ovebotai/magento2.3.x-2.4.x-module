<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Tracking;

use Ovebot\Chat\Api\Data\TrackingResultInterface;

/**
 * The shipment of an order; values are trimmed, an empty URL is no URL.
 */
class TrackingResult implements TrackingResultInterface
{
    /**
     * @var string
     */
    private $carrier;

    /**
     * @var string
     */
    private $awb;

    /**
     * @var string|null
     */
    private $trackingUrl;

    /**
     * @param string $carrier
     * @param string $awb
     * @param string|null $trackingUrl
     */
    public function __construct(string $carrier = '', string $awb = '', ?string $trackingUrl = null)
    {
        $this->carrier = trim($carrier);
        $this->awb = trim($awb);
        $trackingUrl = $trackingUrl !== null ? trim($trackingUrl) : '';
        $this->trackingUrl = $trackingUrl !== '' ? $trackingUrl : null;
    }

    /**
     * @inheritdoc
     */
    public function getCarrier(): string
    {
        return $this->carrier;
    }

    /**
     * @inheritdoc
     */
    public function getAwb(): string
    {
        return $this->awb;
    }

    /**
     * @inheritdoc
     */
    public function getTrackingUrl(): ?string
    {
        return $this->trackingUrl;
    }
}
