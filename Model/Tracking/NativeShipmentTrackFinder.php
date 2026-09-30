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

use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory;
use Ovebot\Chat\Api\Data\TrackingResultInterface;
use Ovebot\Chat\Api\Data\TrackingResultInterfaceFactory;
use Ovebot\Chat\Api\TrackingFinderInterface;

/**
 * Magento's own shipment tracking: the newest tracking number added to a shipment of the order.
 *
 * Courier modules that create the shipment in Magento usually write their tracking number here too, so this
 * source covers them without any code of their own.
 */
class NativeShipmentTrackFinder implements TrackingFinderInterface
{
    /**
     * @var CollectionFactory
     */
    private $trackCollectionFactory;

    /**
     * @var TrackingResultInterfaceFactory
     */
    private $resultFactory;

    /**
     * @var TrackingUrlResolver
     */
    private $urlResolver;

    /**
     * @param CollectionFactory $trackCollectionFactory
     * @param TrackingResultInterfaceFactory $resultFactory
     * @param TrackingUrlResolver $urlResolver
     */
    public function __construct(
        CollectionFactory $trackCollectionFactory,
        TrackingResultInterfaceFactory $resultFactory,
        TrackingUrlResolver $urlResolver
    ) {
        $this->trackCollectionFactory = $trackCollectionFactory;
        $this->resultFactory = $resultFactory;
        $this->urlResolver = $urlResolver;
    }

    /**
     * @inheritdoc
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function find(int $orderId): ?TrackingResultInterface
    {
        $tracks = $this->trackCollectionFactory->create();
        $tracks->addFieldToFilter('main_table.order_id', $orderId)
            ->addFieldToFilter('main_table.track_number', ['neq' => '']);
        // the shipping method of the order names the carrier when the number was added by hand ("Custom Value")
        $tracks->getSelect()
            ->joinLeft(
                ['ovebot_order' => $tracks->getTable('sales_order')],
                'ovebot_order.entity_id = main_table.order_id',
                ['ovebot_shipping_method' => 'ovebot_order.shipping_method']
            )
            ->order('main_table.entity_id DESC')
            ->limit(1);

        foreach ($tracks as $track) {
            $awb = trim((string) $track->getTrackNumber());
            if ($awb === '') {
                continue;
            }

            $title = trim((string) $track->getTitle());
            $carrierCode = trim((string) $track->getCarrierCode());

            return $this->resultFactory->create([
                'carrier' => $title !== '' ? $title : $carrierCode,
                'awb' => $awb,
                'trackingUrl' => $this->urlResolver->resolve(
                    $carrierCode,
                    $title,
                    $awb,
                    (string) $track->getData('ovebot_shipping_method')
                ),
            ]);
        }

        return null;
    }
}
