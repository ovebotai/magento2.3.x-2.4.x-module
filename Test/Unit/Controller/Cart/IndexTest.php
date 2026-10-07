<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Controller\Cart;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ovebot\Chat\Controller\Cart\Index;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\Storefront\CartProvider;
use Ovebot\Chat\Model\Widget\OptionsBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexTest extends TestCase
{
    /**
     * @var array
     */
    private $answer = [];

    /**
     * @var string[]
     */
    private $logged = [];

    protected function setUp(): void
    {
        $this->answer = ['headers' => [], 'data' => null, 'code' => 200];
        $this->logged = [];
    }

    /**
     * @param bool $enabled whether the chat and the switch are on
     * @param array|\Exception $cart what the cart provider gives
     * @param bool $readFails whether the connection cannot be read
     * @return array the answer
     */
    private function ask(bool $enabled, $cart, bool $readFails = false): array
    {
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->createMock(RequestInterface::class));

        $connection = $this->createMock(Connection::class);
        $connections = $this->createMock(ConnectionRepository::class);
        $connections->method('get')->willReturnCallback(function () use ($connection, $readFails) {
            if ($readFails) {
                throw new \RuntimeException("SQLSTATE ... customer_email = 'client@example.ro'");
            }

            return $connection;
        });

        $options = $this->createMock(OptionsBuilder::class);
        $options->method('isCartEnabled')->with($connection)->willReturn($enabled);

        $provider = $this->createMock(CartProvider::class);
        $provider->method('get')->willReturnCallback(function () use ($cart) {
            if ($cart instanceof \Exception) {
                throw $cart;
            }

            return $cart;
        });

        $result = $this->createMock(Json::class);
        $result->method('setHeader')->willReturnCallback(function ($name, $value) use ($result) {
            $this->answer['headers'][$name] = $value;

            return $result;
        });
        $result->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($result) {
            $this->answer['code'] = $code;

            return $result;
        });
        $result->method('setData')->willReturnCallback(function ($data) use ($result) {
            $this->answer['data'] = $data;

            return $result;
        });
        $factory = $this->getMockBuilder(JsonFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($result);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->logged[] = (string) $message;
        });

        (new Index($context, $connections, $options, $provider, $factory, $logger))->execute();

        return $this->answer;
    }

    public function testTheCartOfTheVisitor()
    {
        $cart = [
            'count' => 2,
            'items' => [['ref' => '1042', 'name' => 'A', 'price' => 1.0, 'currency' => 'RON', 'quantity' => 2]],
        ];

        $answer = $this->ask(true, $cart);

        $this->assertSame(200, $answer['code']);
        $this->assertSame($cart, $answer['data']);
        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0', $answer['headers']['Cache-Control']);
        $this->assertSame('noindex, nofollow', $answer['headers']['X-Robots-Tag']);
    }

    public function testForbiddenWhileTheChatOrTheSwitchIsOff()
    {
        $answer = $this->ask(false, ['count' => 1, 'items' => []]);

        $this->assertSame(403, $answer['code']);
        $this->assertSame(['error' => 'Forbidden'], $answer['data']);
    }

    public function testAFailureIsLoggedWithoutItsMessage()
    {
        $answer = $this->ask(true, new \RuntimeException("SQLSTATE ... customer_email = 'client@example.ro'"));

        $this->assertSame(503, $answer['code']);
        $this->assertSame(['error' => 'Unavailable'], $answer['data']);
        $this->assertSame(['Cart not given to the chat (RuntimeException).'], $this->logged);

        $this->assertSame(503, $this->ask(true, [], true)['code']);
    }

    public function testNoFormKeyIsAskedForAReadOfTheOwnCart()
    {
        $controller = (new \ReflectionClass(Index::class))->newInstanceWithoutConstructor();
        $request = $this->createMock(RequestInterface::class);

        $this->assertTrue($controller->validateForCsrf($request));
        $this->assertNull($controller->createCsrfValidationException($request));
    }
}
