<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model;

use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\SetupPayloadBuilder;
use Ovebot\Chat\Model\StoreContext\StoreView;
use PHPUnit\Framework\TestCase;

class SetupPayloadBuilderTest extends TestCase
{
    private const FEED_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ORDERS_URL = 'https://www.shop-test.ro/ovebot/orders/index/';

    /**
     * @param array $stored recommend, builtin, orders, cart, widget
     * @return Connection
     */
    private function connection(array $stored = []): Connection
    {
        $stored += ['recommend' => true, 'builtin' => true, 'orders' => true, 'cart' => true, 'widget' => []];

        $connection = $this->createMock(Connection::class);
        $connection->method('isProductsRecommend')->willReturn($stored['recommend']);
        $connection->method('isProductsBuiltin')->willReturn($stored['builtin']);
        $connection->method('isOrderEnabled')->willReturn($stored['orders']);
        $connection->method('isAddToCart')->willReturn($stored['cart']);
        $connection->method('getWidget')->willReturn($stored['widget']);
        $connection->method('getFeedHash')->willReturn(self::FEED_HASH);
        $connection->method('getOrderUser')->willReturn('shop_test_ro_deadbeef');
        $connection->method('getOrderPass')->willReturn('stored-pass');

        return $connection;
    }

    private function storeView(): StoreView
    {
        $storeView = $this->createMock(StoreView::class);
        $storeView->method('getFeedUrl')->willReturnCallback(function ($hash) {
            return 'https://www.shop-test.ro/ovebot/feed/index/?hash=' . $hash;
        });
        $storeView->method('getOrdersUrl')->willReturn(self::ORDERS_URL);
        $storeView->method('getCurrencyCode')->willReturn('RON');

        return $storeView;
    }

    private function build(array $stored = [], array $overrides = []): array
    {
        return (new SetupPayloadBuilder())->build($this->connection($stored), $this->storeView(), $overrides);
    }

    public function testEverythingOn()
    {
        $this->assertSame(
            [
                'widget' => ['language' => 'auto'],
                'order_info' => [
                    'enabled' => true,
                    'api_url' => self::ORDERS_URL,
                    'api_user' => 'shop_test_ro_deadbeef',
                    'api_password' => 'stored-pass',
                    'lookup_method' => 'email',
                ],
                'products' => [
                    'enabled' => true,
                    'add_to_cart' => true,
                    'feed_url' => 'https://www.shop-test.ro/ovebot/feed/index/?hash=' . self::FEED_HASH,
                    'currency' => 'RON',
                ],
            ],
            $this->build()
        );
    }

    /**
     * The feed URL goes out only while the recommendations are on AND the built-in feed is the source
     *
     * @param bool $recommend
     * @param bool $builtin
     * @param bool $withFeed
     * @dataProvider productSwitches
     */
    public function testFeedUrlFollowsTheTwoSwitches(bool $recommend, bool $builtin, bool $withFeed)
    {
        $products = $this->build(['recommend' => $recommend, 'builtin' => $builtin])['products'];

        $this->assertSame($recommend, $products['enabled']);
        $this->assertSame($withFeed, array_key_exists('feed_url', $products));
        $this->assertSame($withFeed, array_key_exists('currency', $products));
    }

    /**
     * The "Add to cart" switch is always sent, whatever the other switches say; an override wins
     */
    public function testAddToCartIsAlwaysSent()
    {
        $this->assertFalse($this->build(['cart' => false])['products']['add_to_cart']);
        $this->assertFalse($this->build(['cart' => false, 'recommend' => false])['products']['add_to_cart']);
        $this->assertTrue($this->build(['cart' => false], ['add_to_cart' => true])['products']['add_to_cart']);
        $this->assertFalse($this->build(['cart' => true], ['add_to_cart' => false])['products']['add_to_cart']);
        $this->assertSame(
            ['enabled', 'add_to_cart', 'feed_url', 'currency'],
            array_keys($this->build()['products'])
        );
    }

    public static function productSwitches(): array
    {
        return [
            'recommend, built-in feed' => [true, true, true],
            'recommend, own feed' => [true, false, false],
            'no recommendations, built-in feed' => [false, true, false],
            'no recommendations, own feed' => [false, false, false],
        ];
    }

    /**
     * @param bool $recommend
     * @param bool $builtin
     * @param bool $withFeed
     * @dataProvider productSwitches
     */
    public function testSwitchOverridesWinOverTheStoredValues(bool $recommend, bool $builtin, bool $withFeed)
    {
        // the stored values are the opposite of what is asked
        $products = $this->build(
            ['recommend' => !$recommend, 'builtin' => !$builtin],
            ['products_recommend' => $recommend, 'products_builtin' => $builtin]
        )['products'];

        $this->assertSame($recommend, $products['enabled']);
        $this->assertSame($withFeed, array_key_exists('feed_url', $products));
    }

    public function testOrderSectionIsCompleteAlsoWhenSwitchedOff()
    {
        $orders = $this->build(['orders' => false])['order_info'];

        $this->assertFalse($orders['enabled']);
        $this->assertSame(self::ORDERS_URL, $orders['api_url']);
        $this->assertSame('shop_test_ro_deadbeef', $orders['api_user']);
        $this->assertSame('stored-pass', $orders['api_password']);
        $this->assertSame('email', $orders['lookup_method']);
    }

    public function testOrderSwitchOverride()
    {
        $this->assertTrue($this->build(['orders' => false], ['order_enabled' => true])['order_info']['enabled']);
        $this->assertFalse($this->build(['orders' => true], ['order_enabled' => 0])['order_info']['enabled']);
    }

    public function testNewHashAndCredentialsAreSentWithoutBeingStored()
    {
        $payload = $this->build([], [
            'feed_hash' => str_repeat('f', 32),
            'order_user' => 'shop_test_ro_00000000',
            'order_pass' => 'new-pass',
        ]);

        $this->assertSame(
            'https://www.shop-test.ro/ovebot/feed/index/?hash=' . str_repeat('f', 32),
            $payload['products']['feed_url']
        );
        $this->assertSame('shop_test_ro_00000000', $payload['order_info']['api_user']);
        $this->assertSame('new-pass', $payload['order_info']['api_password']);
    }

    public function testAnOverrideThatIsNotTextIsIgnored()
    {
        $payload = $this->build([], ['order_user' => ['x'], 'order_pass' => null]);

        $this->assertSame('shop_test_ro_deadbeef', $payload['order_info']['api_user']);
        $this->assertSame('stored-pass', $payload['order_info']['api_password']);
    }

    /**
     * @param array $widget
     * @param string $expected
     * @dataProvider languages
     */
    public function testWidgetSectionCarriesOnlyTheLanguage(array $widget, string $expected)
    {
        $this->assertSame(['language' => $expected], $this->build(['widget' => $widget])['widget']);
    }

    public static function languages(): array
    {
        return [
            'nothing set' => [[], 'auto'],
            'empty language' => [['language' => ''], 'auto'],
            'language set' => [['language' => 'ro', 'color' => '#ff0000', 'position' => 'left'], 'ro'],
            'language that is not text' => [['language' => ['ro']], 'auto'],
        ];
    }
}
