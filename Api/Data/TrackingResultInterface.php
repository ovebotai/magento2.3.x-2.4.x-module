<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Api\Data;

/**
 * The shipment of an order as told to the AI agent: carrier, tracking number (AWB) and a public tracking page.
 *
 * A tracking source creates it with Ovebot\Chat\Api\Data\TrackingResultInterfaceFactory:
 * create(['carrier' => 'Sameday', 'awb' => '1ONB..', 'trackingUrl' => null]).
 *
 * @api
 */
interface TrackingResultInterface
{
    /**
     * Name of the carrier, as the customer knows it
     *
     * @return string
     */
    public function getCarrier(): string;

    /**
     * Tracking number
     *
     * @return string
     */
    public function getAwb(): string;

    /**
     * Public page where the customer follows the shipment; null when there is none
     *
     * @return string|null
     */
    public function getTrackingUrl(): ?string;
}
