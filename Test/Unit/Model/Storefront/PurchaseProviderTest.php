<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Storefront;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ovebot\Chat\Model\Storefront\PurchaseProvider;
use Ovebot\Chat\Observer\CheckoutSuccessAction;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PurchaseProviderTest extends TestCase
{
    /**
     * @var array order id => [grand total, currency, lines]; a line is
     *            [item id, parent item id, product id, type, name, price with tax, qty]
     */
    private $orders = [];

    /**
     * @var array what the checkout session holds
     */
    private $session = [];

    /**
     * @var int[] orders read from the repository
     */
    private $loaded = [];

    /**
     * @var string[]
     */
    private $logged = [];

    protected function setUp(): void
    {
        $this->orders = [
            41 => ['199.9', 'RON', [
                [7, null, '1042', 'simple', 'Wireless Headphones Pro', '199.9', '1.0000'],
            ]],
            42 => ['10.004', 'EUR', [
                // a configurable product: the parent line carries the price, the child line the variant
                [8, null, '14', 'configurable', 'Tricou bumbac', '5.002', '2.0000'],
                [9, 8, '31', 'simple', 'Tricou bumbac-Roșu-M', '0', '2.0000'],
            ]],
            43 => ['0.5', 'RON', []],
        ];
        $this->session = [];
        $this->loaded = [];
        $this->logged = [];
    }

    private function provider(): PurchaseProvider
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function ($id) {
            $this->loaded[] = $id;
            if ($id === 99) {
                throw new \RuntimeException("SQLSTATE: ... customer_email = 'client@example.ro'");
            }
            if (!isset($this->orders[$id])) {
                throw new NoSuchEntityException(__('No such order.'));
            }
            $order = $this->createMock(OrderInterface::class);
            $order->method('getEntityId')->willReturn((string) $id);
            $order->method('getGrandTotal')->willReturn($this->orders[$id][0]);
            $order->method('getOrderCurrencyCode')->willReturn($this->orders[$id][1]);
            $order->method('getItems')->willReturn(array_map([$this, 'line'], $this->orders[$id][2]));

            return $order;
        });

        // the session keeps its values through magic methods: setData() reaches __call()
        $session = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', '__call'])
            ->getMock();
        $session->method('getData')->willReturnCallback(function ($key = '') {
            return isset($this->session[$key]) ? $this->session[$key] : null;
        });
        $session->method('__call')->willReturnCallback(function ($method, $args) use ($session) {
            $this->assertSame('setData', $method);
            $this->session[$args[0]] = $args[1];

            return $session;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->logged[] = (string) $message;
        });

        return new PurchaseProvider($repository, $session, $logger);
    }

    /**
     * @param array $data item id, parent item id, product id, type, name, price with tax, qty
     * @return OrderItemInterface
     */
    private function line(array $data): OrderItemInterface
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getItemId')->willReturn($data[0]);
        $item->method('getParentItemId')->willReturn($data[1]);
        $item->method('getProductId')->willReturn($data[2]);
        $item->method('getProductType')->willReturn($data[3]);
        $item->method('getName')->willReturn($data[4]);
        $item->method('getPriceInclTax')->willReturn($data[5]);
        $item->method('getQtyOrdered')->willReturn($data[6]);

        return $item;
    }

    public function testNothingWithoutOrders()
    {
        $this->assertSame([], $this->provider()->getPurchases());
        $this->assertSame([], $this->loaded);
        $this->assertSame([], $this->session);
    }

    public function testOnePurchasePerOrderWithTotalAndCurrency()
    {
        $provider = $this->provider();
        $provider->setOrderIds([41, '42']);

        $this->assertSame(
            [
                [
                    'transaction_id' => 41,
                    'total' => 199.9,
                    'currency' => 'RON',
                    'items' => [
                        [
                            'item_id' => '1042',
                            'item_name' => 'Wireless Headphones Pro',
                            'price' => 199.9,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'transaction_id' => 42,
                    'total' => 10.0,
                    'currency' => 'EUR',
                    // the variant is named as in the feed: parent id, child id; the child is not a line
                    'items' => [
                        ['item_id' => '14-31', 'item_name' => 'Tricou bumbac', 'price' => 5.0, 'quantity' => 2],
                    ],
                ],
            ],
            $provider->getPurchases()
        );
        $this->assertSame([41, 42], $this->session[PurchaseProvider::SESSION_KEY]);
    }

    public function testTheSamePageAgainReportsNothing()
    {
        $provider = $this->provider();
        $provider->setOrderIds([41]);
        $provider->getPurchases();

        // the success page opened again, in a new request
        $again = $this->provider();
        $again->setOrderIds([41]);

        $this->assertSame([], $again->getPurchases());
        $this->assertSame([41], $this->loaded, 'a reported order is not read again');
    }

    public function testOnlyTheNewOrdersOfAPageAreReported()
    {
        $this->session[PurchaseProvider::SESSION_KEY] = [41];

        $provider = $this->provider();
        $provider->setOrderIds([41, 43]);

        $this->assertSame([43], array_column($provider->getPurchases(), 'transaction_id'));
        $this->assertSame([41, 43], $this->session[PurchaseProvider::SESSION_KEY]);
    }

    public function testThePurchasesOfARequestAreCollectedOnce()
    {
        $provider = $this->provider();
        $provider->setOrderIds([41]);

        $first = $provider->getPurchases();

        $this->assertSame($first, $provider->getPurchases());
        $this->assertSame([41], $this->loaded);
    }

    public function testTheSessionKeepsTheLastTenOrders()
    {
        $this->session[PurchaseProvider::SESSION_KEY] = range(1, 10);

        $provider = $this->provider();
        $provider->setOrderIds([41]);
        $provider->getPurchases();

        $this->assertSame(array_merge(range(2, 10), [41]), $this->session[PurchaseProvider::SESSION_KEY]);
    }

    public function testMissingAndBrokenOrdersAreSkipped()
    {
        $provider = $this->provider();
        $provider->setOrderIds([404, 99, 41]);

        $this->assertSame([41], array_column($provider->getPurchases(), 'transaction_id'));
        $this->assertSame([41], $this->session[PurchaseProvider::SESSION_KEY]);
        // the kind of error only, never the message
        $this->assertSame(['Purchase not reported (RuntimeException).'], $this->logged);
    }

    public function testIdsThatAreNotOrdersAreDropped()
    {
        $provider = $this->provider();
        $provider->setOrderIds([null, 0, -3, 'x', [41], 41, 41]);

        $this->assertCount(1, $provider->getPurchases());
        $this->assertSame([41], $this->loaded);
    }

    public function testAStoredValueThatIsNotAListIsIgnored()
    {
        $this->session[PurchaseProvider::SESSION_KEY] = 'broken';

        $provider = $this->provider();
        $provider->setOrderIds([41]);

        $this->assertCount(1, $provider->getPurchases());
        $this->assertSame([41], $this->session[PurchaseProvider::SESSION_KEY]);
    }

    public function testTheObserverHandsOverTheOrdersOfTheSuccessPage()
    {
        $provider = $this->provider();
        $observer = new CheckoutSuccessAction($provider);

        $observer->execute(new Observer(['event' => new Event(['order_ids' => ['41']])]));
        $this->assertSame([41], array_column($provider->getPurchases(), 'transaction_id'));

        // an event without a list of orders changes nothing
        $observer->execute(new Observer(['event' => new Event(['order_ids' => null])]));
        $this->assertCount(1, $provider->getPurchases());
    }
}
