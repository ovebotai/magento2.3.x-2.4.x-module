<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Controller\Orders;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Ovebot\Chat\Controller\Orders\Index;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\Order\OrderIdentifier;
use Ovebot\Chat\Model\Order\OrderLookup;
use Ovebot\Chat\Model\Order\PhoneNormalizer;
use Ovebot\Chat\Model\Security\BasicAuth;
use Ovebot\Chat\Model\Security\RateLimiter;
use Ovebot\Chat\Model\Util\ErrorSummary;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexTest extends TestCase
{
    private const IP = '203.0.113.7';
    private const USER = 'demo-shops-ro_1a2b3c4d';
    private const PASS = '0123456789abcdef0123456789abcdef';

    /**
     * @var array state of the fakes
     */
    private $state;

    /**
     * @var array what the answer got: status, headers, data
     */
    private $answer;

    /**
     * @var array calls made to the rate limiter and to the lookup
     */
    private $calls;

    protected function setUp(): void
    {
        $this->state = [
            'blocked' => false,
            'chat' => true,
            'orders' => true,
            'authorization' => 'Basic ' . base64_encode(self::USER . ':' . self::PASS),
            'body' => '{"id": "#000000123", "email": "client@example.ro"}',
            'params' => [],
            'found' => ['id' => 123, 'reference' => '000000123'],
            'lookupFails' => false,
        ];
        $this->answer = ['status' => null, 'headers' => [], 'data' => null];
        $this->calls = ['failures' => 0, 'resets' => 0, 'lookups' => [], 'logged' => []];
    }

    private function controller(): Index
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getServer')->willReturn(null);
        $request->method('getHeader')->willReturnCallback(function ($name) {
            return strcasecmp($name, 'Authorization') === 0 ? $this->state['authorization'] : false;
        });
        $request->method('getContent')->willReturnCallback(function () {
            return $this->state['body'];
        });
        $request->method('getParam')->willReturnCallback(function ($name) {
            return isset($this->state['params'][$name]) ? $this->state['params'][$name] : null;
        });

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $connection = $this->createMock(Connection::class);
        $connection->method('isChatEnabled')->willReturnCallback(function () {
            return $this->state['chat'];
        });
        $connection->method('isOrderEnabled')->willReturnCallback(function () {
            return $this->state['orders'];
        });
        $connection->method('getOrderUser')->willReturn(self::USER);
        $connection->method('getOrderPass')->willReturn(self::PASS);
        $connections = $this->createMock(ConnectionRepository::class);
        $connections->method('get')->willReturn($connection);

        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->method('isBlocked')->willReturnCallback(function ($ip) {
            $this->assertSame(self::IP, $ip);

            return $this->state['blocked'];
        });
        $rateLimiter->method('recordFailure')->willReturnCallback(function ($ip) {
            $this->assertSame(self::IP, $ip);
            $this->calls['failures']++;
        });
        $rateLimiter->method('reset')->willReturnCallback(function ($ip) {
            $this->assertSame(self::IP, $ip);
            $this->calls['resets']++;
        });

        $lookup = $this->createMock(OrderLookup::class);
        $lookup->method('find')->willReturnCallback(function ($identifier, $type, $value) {
            $this->calls['lookups'][] = [$identifier, $type, $value];
            if ($this->state['lookupFails']) {
                throw new \RuntimeException("SQLSTATE: ... customer_email = 'client@example.ro'");
            }

            return $this->state['found'];
        });

        $remoteAddress = $this->createMock(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn(self::IP);

        $result = $this->createMock(Json::class);
        $result->method('setHttpResponseCode')->willReturnCallback(function ($status) use ($result) {
            $this->answer['status'] = $status;

            return $result;
        });
        $result->method('setHeader')->willReturnCallback(function ($name, $value) use ($result) {
            $this->answer['headers'][$name] = $value;

            return $result;
        });
        $result->method('setData')->willReturnCallback(function ($data) use ($result) {
            $this->answer['data'] = $data;

            return $result;
        });
        $resultFactory = $this->getMockBuilder(JsonFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $resultFactory->method('create')->willReturn($result);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->calls['logged'][] = $message;
        });

        return new Index(
            $context,
            $connections,
            $rateLimiter,
            new BasicAuth(),
            new OrderIdentifier(),
            new PhoneNormalizer(),
            $lookup,
            new ErrorSummary(),
            $remoteAddress,
            $resultFactory,
            $logger
        );
    }

    public function testOrderFound()
    {
        $this->controller()->execute();

        $this->assertSame(200, $this->answer['status']);
        $this->assertSame(['success' => true, 'data' => $this->state['found']], $this->answer['data']);
        $this->assertSame(
            [[['increment_ids' => ['000000123'], 'entity_id' => 123], 'email', 'client@example.ro']],
            $this->calls['lookups']
        );
        $this->assertSame(1, $this->calls['resets'], 'a success forgets the failures of the client');
        $this->assertSame('no-store', $this->answer['headers']['Cache-Control']);
    }

    public function testOrderNotFound()
    {
        $this->state['found'] = null;
        $this->controller()->execute();

        $this->assertSame(200, $this->answer['status']);
        $this->assertSame(['success' => false, 'error' => 'Order not found.'], $this->answer['data']);
    }

    public function testBlockedClientComesFirst()
    {
        $this->state['blocked'] = true;
        $this->state['chat'] = false;
        $this->state['authorization'] = false;
        $this->controller()->execute();

        $this->assertSame(429, $this->answer['status']);
        $this->assertSame(['success' => false, 'error' => 'Too many failed attempts.'], $this->answer['data']);
        $this->assertSame('3600', $this->answer['headers']['Retry-After']);
        $this->assertSame(0, $this->calls['failures']);
    }

    public function testBlockedClientWithWrongCredentialsIsNotCountedAgain()
    {
        $this->state['blocked'] = true;
        $this->state['authorization'] = 'Basic ' . base64_encode(self::USER . ':' . str_repeat('0', 32));
        $this->controller()->execute();

        $this->assertSame(429, $this->answer['status']);
        $this->assertSame(0, $this->calls['failures']);
        $this->assertSame([], $this->calls['lookups']);
    }

    public function testABlockNeverStopsTheRightCredentials()
    {
        // behind a proxy the wrong requests of anyone block the address Ovebot.ai comes from as well
        $this->state['blocked'] = true;
        $this->controller()->execute();

        $this->assertSame(200, $this->answer['status']);
        $this->assertSame(['success' => true, 'data' => $this->state['found']], $this->answer['data']);
        $this->assertSame(0, $this->calls['failures']);
        $this->assertCount(1, $this->calls['lookups']);
    }

    public function testBlockedClientWithTheRightCredentialsStillReadsSwitchedOff()
    {
        $this->state['blocked'] = true;
        $this->state['orders'] = false;
        $this->controller()->execute();

        $this->assertSame(403, $this->answer['status']);
        $this->assertSame([], $this->calls['lookups']);
    }

    /**
     * @dataProvider switchedOff
     */
    public function testSwitchedOffBeforeTheCredentials(bool $chat, bool $orders)
    {
        $this->state['chat'] = $chat;
        $this->state['orders'] = $orders;
        $this->state['authorization'] = 'Basic ' . base64_encode('wrong:wrong');
        $this->controller()->execute();

        $this->assertSame(403, $this->answer['status']);
        $this->assertSame(['success' => false, 'error' => 'Forbidden'], $this->answer['data']);
        $this->assertSame(0, $this->calls['failures'], 'not counted: the credentials were not checked');
    }

    public static function switchedOff(): array
    {
        return [
            'chat off' => [false, true],
            'order tracking off' => [true, false],
            'both off' => [false, false],
        ];
    }

    /**
     * @dataProvider wrongCredentials
     */
    public function testWrongCredentialsAreCounted($authorization)
    {
        $this->state['authorization'] = $authorization;
        $this->controller()->execute();

        $this->assertSame(401, $this->answer['status']);
        $this->assertSame(['success' => false, 'error' => 'Unauthorized'], $this->answer['data']);
        $this->assertSame('Basic realm="Ovebot.ai"', $this->answer['headers']['WWW-Authenticate']);
        $this->assertSame(1, $this->calls['failures']);
        $this->assertSame(0, $this->calls['resets']);
        $this->assertSame([], $this->calls['lookups']);
    }

    public static function wrongCredentials(): array
    {
        return [
            'no credentials' => [false],
            'wrong password' => ['Basic ' . base64_encode(self::USER . ':' . str_repeat('0', 32))],
            'unknown user' => ['Basic ' . base64_encode('someone:' . self::PASS)],
        ];
    }

    /**
     * @dataProvider badRequests
     */
    public function testBadRequests(string $body, string $error)
    {
        $this->state['body'] = $body;
        $this->controller()->execute();

        $this->assertSame(400, $this->answer['status']);
        $this->assertSame(['success' => false, 'error' => $error], $this->answer['data']);
        $this->assertSame([], $this->calls['lookups']);
    }

    public static function badRequests(): array
    {
        return [
            'no order number' => ['{"email": "client@example.ro"}', 'Invalid request.'],
            'order number of text only' => ['{"id": "my order", "email": "client@example.ro"}', 'Invalid request.'],
            'neither email nor phone' => ['{"id": "123"}', 'Invalid request.'],
            'both email and phone' => ['{"id": "123", "email": "a@b.ro", "phone": "0721234567"}', 'Invalid request.'],
            'email that is not one' => ['{"id": "123", "email": "client at example"}', 'Invalid email.'],
            'email as a list' => ['{"id": "123", "email": ["a@b.ro"], "phone": ""}', 'Invalid request.'],
            'phone too short' => ['{"id": "123", "phone": "12345"}', 'Invalid phone.'],
        ];
    }

    public function testByPhone()
    {
        $this->state['body'] = '{"id": "ORD-12", "phone": "+40 721-234.567"}';
        $this->controller()->execute();

        $this->assertSame(200, $this->answer['status']);
        $this->assertSame(
            [[['increment_ids' => ['ORD-12'], 'entity_id' => null], 'phone', '721234567']],
            $this->calls['lookups']
        );
    }

    public function testFormFieldsWhenTheBodyIsNotJson()
    {
        $this->state['body'] = 'id=123&email=client%40example.ro';
        $this->state['params'] = ['id' => '123', 'email' => 'client@example.ro'];
        $this->controller()->execute();

        $this->assertSame(200, $this->answer['status']);
        $this->assertSame('client@example.ro', $this->calls['lookups'][0][2]);
    }

    public function testALookupErrorIsLoggedWithoutPersonalData()
    {
        $this->state['lookupFails'] = true;
        $this->controller()->execute();

        $this->assertSame(500, $this->answer['status']);
        $this->assertSame(['success' => false, 'error' => 'Order lookup failed.'], $this->answer['data']);
        $this->assertCount(1, $this->calls['logged']);
        $this->assertStringNotContainsString('client@example.ro', $this->calls['logged'][0]);
        $this->assertStringContainsString('RuntimeException thrown in ' . __FILE__, $this->calls['logged'][0]);
        $this->assertStringContainsString(
            'called from ' . Index::class . '::execute() line',
            $this->calls['logged'][0]
        );
    }

    public function testNoFormKeyIsAskedFor()
    {
        $controller = $this->controller();
        $request = $this->createMock(HttpRequest::class);

        $this->assertTrue($controller->validateForCsrf($request));
        $this->assertNull($controller->createCsrfValidationException($request));
    }
}
