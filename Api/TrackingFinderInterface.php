<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Api;

/**
 * A source of tracking numbers (AWB) for an order.
 *
 * Magento's own shipment tracking is built in. A courier module that keeps its tracking numbers elsewhere adds a
 * source in its di.xml, in the argument "finders" of Ovebot\Chat\Model\Tracking\TrackingResolver:
 *
 *     <item name="mycourier" xsi:type="array">
 *         <item name="finder" xsi:type="object">Vendor\Module\Model\MyCourierFinder</item>
 *         <item name="label" xsi:type="string" translatable="true">My Courier</item>
 *         <item name="sortOrder" xsi:type="number">50</item>
 *     </item>
 *
 * The item name is the code of the source (lower case letters, digits, "_"); the label is shown in
 * Stores > Configuration > Ovebot AI > Shipment tracking. Sources run in ascending sortOrder; the built-in one
 * has 10.
 *
 * @api
 */
interface TrackingFinderInterface
{
    /**
     * Cheap check: can the source be used in this installation (module enabled, table present)?
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * The shipment of the order, or null when the source knows none
     *
     * @param int $orderId sales_order.entity_id
     * @return \Ovebot\Chat\Api\Data\TrackingResultInterface|null
     */
    public function find(int $orderId): ?Data\TrackingResultInterface;
}
