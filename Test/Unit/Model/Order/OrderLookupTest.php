<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Order;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Ovebot\Chat\Model\Config;
use Ovebot\Chat\Model\Order\OrderLookup;
use Ovebot\Chat\Model\StoreEmulator;
use Ovebot\Chat\Model\Tracking\TrackingResolver;
use Ovebot\Chat\Model\Tracking\TrackingResult;
use PHPUnit\Framework\TestCase;

class OrderLookupTest extends TestCase
{
    /**
     * @var CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $collectionFactory;

    /**
     * @var array store view emulated for each description, and what the collection was asked
     */
    private $seen = [];

    protected function setUp(): void
    {
        $this->seen = ['emulated' => [], 'filters' => [], 'where' => [], 'order' => []];
    }

    private function lookup(?TrackingResult $tracking = null, string $timezone = 'Europe/Bucharest'): OrderLookup
    {
        $this->collectionFactory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $resolver = $this->createMock(TrackingResolver::class);
        $resolver->method('resolve')->willReturnCallback(function ($orderId) use ($tracking) {
            $this->assertSame(123, $orderId);

            return $tracking;
        });

        $timezoneModel = $this->createMock(TimezoneInterface::class);
        $timezoneModel->method('getConfigTimezone')->willReturn($timezone);

        $emulator = $this->createMock(StoreEmulator::class);
        $emulator->method('runOn')->willReturnCallback(function ($storeId, callable $callback) {
            $this->seen['emulated'][] = $storeId;

            return $callback();
        });

        $config = $this->createMock(Config::class);
        $config->method('getMaxOrderAgeDays')->willReturn(60);

        return new OrderLookup($this->collectionFactory, $config, $resolver, $timezoneModel, $emulator);
    }

    private function order(array $data, $statusLabel = 'Processing'): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStatusLabel'])
            ->getMock();
        $order->setData($data + [
            'entity_id' => 123,
            'increment_id' => '000000123',
            'created_at' => '2026-09-20 07:15:00',
            'status' => 'processing',
            'grand_total' => '199.9000',
            'order_currency_code' => 'RON',
        ]);
        if ($statusLabel instanceof \Throwable) {
            $order->method('getStatusLabel')->willThrowException($statusLabel);
        } else {
            $order->method('getStatusLabel')->willReturn($statusLabel);
        }

        return $order;
    }

    public function testDescriptionWithTracking()
    {
        $lookup = $this->lookup(new TrackingResult('Sameday', '1ONB42', 'https://sameday.ro/#awb=1ONB42'));

        $this->assertSame(
            [
                'id' => 123,
                'reference' => '000000123',
                // created_at is UTC; Bucharest is UTC+3 in September
                'date' => '2026-09-20 10:15:00',
                'status' => 'Processing',
                'total' => 199.9,
                'currency' => 'RON',
                'carrier' => 'Sameday',
                'awb' => '1ONB42',
                'awb_tracking_url' => 'https://sameday.ro/#awb=1ONB42',
            ],
            $lookup->describe($this->order([]))
        );
    }

    public function testDescriptionWithoutTracking()
    {
        $data = $this->lookup()->describe($this->order(['grand_total' => '10.4567']));

        $this->assertSame([null, null, null], [$data['carrier'], $data['awb'], $data['awb_tracking_url']]);
        $this->assertSame(10.46, $data['total']);
    }

    public function testNumberWithoutACarrierName()
    {
        $data = $this->lookup(new TrackingResult('', 'X1', null))->describe($this->order([]));

        $this->assertSame([null, 'X1', null], [$data['carrier'], $data['awb'], $data['awb_tracking_url']]);
    }

    public function testStatusCodeWhenTheStatusHasNoLabel()
    {
        $this->assertSame('processing', $this->lookup()->describe($this->order([], ''))['status']);
        $this->assertSame(
            'processing',
            $this->lookup()->describe($this->order([], new \TypeError('label is null')))['status']
        );
    }

    public function testDateInWinterTime()
    {
        $data = $this->lookup()->describe($this->order(['created_at' => '2026-01-10 23:30:00']));

        $this->assertSame('2026-01-11 01:30:00', $data['date']);
    }

    public function testDateAsStoredWhenTheTimeZoneIsUnknown()
    {
        $data = $this->lookup(null, 'Mars/Olympus')->describe($this->order([]));

        $this->assertSame('2026-09-20 07:15:00', $data['date']);
    }

    /**
     * A collection that records what it is asked and holds the given orders
     *
     * @param Order[] $orders
     */
    private function collection(array $orders): void
    {
        $subquery = $this->createMock(Select::class);
        $subquery->method('from')->willReturnSelf();
        $subquery->method('where')->willReturnCallback(function ($condition) use ($subquery) {
            $this->seen['where'][] = 'address: ' . $condition;

            return $subquery;
        });
        $subquery->method('assemble')->willReturn('SELECT address');

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($subquery);
        $connection->method('quoteInto')->willReturnCallback(function ($text, $value) {
            return str_replace('?', "'" . implode("','", (array) $value) . "'", $text);
        });

        $select = $this->createMock(Select::class);
        $select->method('where')->willReturnCallback(function ($condition) use ($select) {
            $this->seen['where'][] = $condition;

            return $select;
        });
        $select->method('order')->willReturnCallback(function ($order) use ($select) {
            $this->seen['order'][] = (string) $order;

            return $select;
        });
        $select->method('limit')->willReturnSelf();

        $collection = $this->createMock(Collection::class);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getTable')->willReturnArgument(0);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) use ($collection) {
            $this->seen['filters'][] = [$field, $condition];

            return $collection;
        });
        $collection->method('getIterator')->willReturn(new \ArrayIterator($orders));

        $this->collectionFactory->method('create')->willReturn($collection);
    }

    public function testTheOrderIsDescribedInItsOwnStoreView()
    {
        $lookup = $this->lookup();
        $this->collection([$this->order(['store_id' => '3'])]);

        $data = $lookup->find(
            ['increment_ids' => ['123', '000000123'], 'entity_id' => 123],
            OrderLookup::TYPE_EMAIL,
            'client@example.ro',
            1790000000
        );

        $this->assertSame(123, $data['id']);
        $this->assertSame([3], $this->seen['emulated']);
        $this->assertSame(
            [
                ['main_table.created_at', ['gteq' => gmdate('Y-m-d H:i:s', 1790000000 - 60 * 86400)]],
                [
                    ['main_table.increment_id', 'main_table.entity_id'],
                    [['in' => ['123', '000000123']], ['eq' => 123]],
                ],
                ['main_table.customer_email', ['eq' => 'client@example.ro']],
            ],
            $this->seen['filters']
        );
        $this->assertSame(
            ["main_table.increment_id IN ('123','000000123') DESC", 'main_table.entity_id DESC'],
            $this->seen['order'],
            'an order number match comes first, then the newest order'
        );
    }

    public function testAnOrderWhoseStoreViewWasDeleted()
    {
        $lookup = $this->lookup();
        $this->collection([$this->order(['store_id' => null])]);

        $lookup->find(['increment_ids' => ['000000123'], 'entity_id' => 123], OrderLookup::TYPE_EMAIL, 'a@b.ro', 1);

        $this->assertSame([null], $this->seen['emulated'], 'the emulator stands in with the default store view');
    }

    public function testByPhoneTheAddressesAreSearched()
    {
        $lookup = $this->lookup();
        $this->collection([$this->order([])]);

        $lookup->find(['increment_ids' => [], 'entity_id' => 123], OrderLookup::TYPE_PHONE, '721234567', 1);

        $this->assertSame('address: address.parent_id = main_table.entity_id', $this->seen['where'][0]);
        $this->assertStringContainsString("REPLACE(COALESCE(address.telephone, '')", $this->seen['where'][1]);
        $this->assertStringEndsWith(' LIKE ?', $this->seen['where'][1]);
        $this->assertSame('EXISTS (SELECT address)', $this->seen['where'][2]);
        $this->assertSame(['main_table.entity_id DESC'], $this->seen['order']);
    }

    public function testNoOrderNoDescription()
    {
        $lookup = $this->lookup();
        $this->collection([]);

        $identifier = ['increment_ids' => ['9'], 'entity_id' => 9];

        $this->assertNull($lookup->find($identifier, OrderLookup::TYPE_EMAIL, 'a@b.ro', 1));
        $this->assertSame([], $this->seen['emulated']);
    }

    /**
     * @dataProvider unusableQueries
     */
    public function testNothingIsQueriedForAnUnusableRequest(array $identifier, string $type, string $value)
    {
        $lookup = $this->lookup();
        $this->collectionFactory->expects($this->never())->method('create');

        $this->assertNull($lookup->find($identifier, $type, $value, 1790000000));
    }

    public static function unusableQueries(): array
    {
        return [
            'no candidates' => [['increment_ids' => [], 'entity_id' => null], 'email', 'a@b.ro'],
            'empty candidates' => [['increment_ids' => ['', ''], 'entity_id' => 0], 'email', 'a@b.ro'],
            'nothing to compare' => [['increment_ids' => ['000000123'], 'entity_id' => 123], 'email', ' '],
            'unknown type' => [['increment_ids' => ['000000123'], 'entity_id' => 123], 'name', 'Ion'],
        ];
    }
}
