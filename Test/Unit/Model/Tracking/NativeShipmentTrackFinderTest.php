<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Tracking;

use Magento\Framework\DB\Select;
use Magento\Framework\DataObject;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory;
use Ovebot\Chat\Api\Data\TrackingResultInterfaceFactory;
use Ovebot\Chat\Model\Tracking\NativeShipmentTrackFinder;
use Ovebot\Chat\Model\Tracking\TrackingResult;
use Ovebot\Chat\Model\Tracking\TrackingUrlResolver;
use PHPUnit\Framework\TestCase;

class NativeShipmentTrackFinderTest extends TestCase
{
    /**
     * @var array filters put on the collection
     */
    private $filters = [];

    /**
     * @var array calls to the URL resolver
     */
    private $urlCalls = [];

    protected function setUp(): void
    {
        $this->filters = [];
        $this->urlCalls = [];
    }

    private function finder(array $tracks): NativeShipmentTrackFinder
    {
        $select = $this->createMock(Select::class);
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) use ($collection) {
            $this->filters[] = [$field, $condition];

            return $collection;
        });
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getTable')->willReturnArgument(0);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($tracks));

        $collectionFactory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $collectionFactory->method('create')->willReturn($collection);

        $resultFactory = $this->getMockBuilder(TrackingResultInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $resultFactory->method('create')->willReturnCallback(function (array $data) {
            return new TrackingResult($data['carrier'], $data['awb'], $data['trackingUrl']);
        });

        $urlResolver = $this->createMock(TrackingUrlResolver::class);
        $urlResolver->method('resolve')->willReturnCallback(function ($code, $title, $awb, $shippingMethod) {
            $this->urlCalls[] = [$code, $title, $awb, $shippingMethod];

            return $code === 'custom' && $title === 'Sameday' ? 'https://sameday.ro/#awb=' . $awb : null;
        });

        return new NativeShipmentTrackFinder($collectionFactory, $resultFactory, $urlResolver);
    }

    private function track(string $number, string $title, string $carrierCode, ?string $method = null): DataObject
    {
        return new DataObject([
            'track_number' => $number,
            'title' => $title,
            'carrier_code' => $carrierCode,
            'ovebot_shipping_method' => $method,
        ]);
    }

    public function testTheNewestTrackOfTheOrder()
    {
        $result = $this->finder([$this->track(' 1ONB42 ', 'Sameday', 'custom')])->find(42);

        $this->assertSame(
            ['Sameday', '1ONB42', 'https://sameday.ro/#awb=1ONB42'],
            [$result->getCarrier(), $result->getAwb(), $result->getTrackingUrl()]
        );
        $this->assertSame([['main_table.order_id', 42], ['main_table.track_number', ['neq' => '']]], $this->filters);
        $this->assertSame([['custom', 'Sameday', '1ONB42', '']], $this->urlCalls);
    }

    public function testTheShippingMethodOfTheOrderGoesToTheUrlResolver()
    {
        $this->finder([$this->track('F1', 'AWB', 'custom', 'fancourier_standard')])->find(42);

        $this->assertSame([['custom', 'AWB', 'F1', 'fancourier_standard']], $this->urlCalls);
    }

    public function testTheCarrierCodeWhenTheTitleIsEmpty()
    {
        $result = $this->finder([$this->track('1Z999', '', 'ups')])->find(42);

        $this->assertSame('ups', $result->getCarrier());
        $this->assertNull($result->getTrackingUrl());
    }

    public function testNoTrack()
    {
        $this->assertNull($this->finder([])->find(42));
        $this->assertNull($this->finder([$this->track('   ', 'Sameday', 'custom')])->find(42));
    }

    public function testAlwaysAvailable()
    {
        $this->assertTrue($this->finder([])->isAvailable());
    }
}
