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

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Ovebot\Chat\Model\Api\Client;
use Ovebot\Chat\Model\Api\ErrorParser;
use Ovebot\Chat\Model\Api\Exception\AuthException;
use Ovebot\Chat\Model\Api\Exception\ConnectionException;
use Ovebot\Chat\Model\Api\Response;
use Ovebot\Chat\Model\Cache\WidgetCache;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\OauthStateStorage;
use Ovebot\Chat\Model\ResourceModel\Connection as ConnectionResource;
use Ovebot\Chat\Model\ResourceModel\RateLimit as RateLimitResource;
use Ovebot\Chat\Model\Security\Random;
use Ovebot\Chat\Model\SetupPayloadBuilder;
use Ovebot\Chat\Model\StoreContext\StoreView;
use Ovebot\Chat\Model\Widget\OptionsBuilder;
use Ovebot\Chat\Model\Widget\Settings as WidgetSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class IntegrationTest extends TestCase
{

    /**
     * @var array calls made to the client: [key, body, token]
     */
    private $calls = [];

    /**
     * @var string token the client holds
     */
    private $token = '';

    /**
     * @var array "METHOD path" => Response, or a list of them returned in order
     */
    private $responses = [];

    /**
     * @var array|\Exception|null
     */
    private $refreshAnswer;

    /**
     * @var array|\Exception|null
     */
    private $exchangeAnswer;

    /**
     * @var bool
     */
    private $throwConnection = false;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var Connection|null what the repository reads from the database under the lock
     */
    private $reloaded;

    /**
     * @var int
     */
    private $saves = 0;

    /**
     * @var bool
     */
    private $saveFails = false;

    /**
     * @var bool
     */
    private $lockAvailable = true;

    /**
     * @var string[] lock and unlock calls
     */
    private $locks = [];

    /**
     * @var array arguments of OauthStateStorage::add()
     */
    private $states = [];

    /**
     * @var string[]
     */
    private $logged = [];

    /**
     * @var string base URL of the storefront
     */
    private $baseUrl = 'https://www.shop-test.ro/';

    /**
     * @var int times the rate limit table was emptied
     */
    private $rateLimitClears = 0;

    /**
     * @var bool
     */
    private $rateLimitFails = false;

    /**
     * @var int times the cached pages with the widget were removed
     */
    private $widgetCleans = 0;

    protected function setUp(): void
    {
        $this->widgetCleans = 0;
        $this->rateLimitClears = 0;
        $this->rateLimitFails = false;
        $this->calls = [];
        $this->token = '';
        $this->responses = [];
        $this->refreshAnswer = null;
        $this->exchangeAnswer = null;
        $this->throwConnection = false;
        $this->reloaded = null;
        $this->saves = 0;
        $this->saveFails = false;
        $this->lockAvailable = true;
        $this->locks = [];
        $this->states = [];
        $this->logged = [];
        $this->baseUrl = 'https://www.shop-test.ro/';
        $this->connection = $this->newConnection();
    }

    private function newConnection(): Connection
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(function ($value) {
            return 'enc:' . strrev((string) $value);
        });
        $encryptor->method('decrypt')->willReturnCallback(function ($value) {
            return strrev(substr((string) $value, 4));
        });

        $connection = new Connection(
            $this->createMock(Context::class),
            $this->createMock(Registry::class),
            $encryptor,
            new Json(),
            $this->createMock(ConnectionResource::class)
        );

        return $connection;
    }

    private function connect(Connection $connection, string $access = 'AT1', string $refresh = 'RT1'): Connection
    {
        $connection->setTokens($access, $refresh, time() + 3600)
            ->setWorkspace('my-shop')
            ->setAgent('')
            ->setFeedHash(str_repeat('a', 32))
            ->setOrderCredentials('shop_test_ro_deadbeef', str_repeat('b', 32));

        return $connection;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function integration(): Integration
    {
        $this->token = $this->connection->getAccessToken();

        $client = $this->createMock(Client::class);
        $client->method('setAccessToken')->willReturnCallback(function ($token) use ($client) {
            $this->token = $token;

            return $client;
        });
        $client->method('generateVerifier')->willReturn('verifier-1');
        $client->method('generateState')->willReturn(str_repeat('c', 32));
        $client->method('buildAuthUrl')->willReturnCallback(function (...$args) {
            return 'authorize|' . implode('|', $args);
        });
        $client->method('buildRegisterUrl')->willReturnCallback(function (...$args) {
            return 'register|' . implode('|', $args);
        });
        $client->method('apiRequest')->willReturnCallback(function ($method, $path, $body = null) {
            $key = strtoupper($method) . ' ' . preg_replace('/\?.*/', '', $path);
            $this->calls[] = ['key' => $key, 'body' => $body, 'token' => $this->token];
            if ($this->throwConnection) {
                throw new ConnectionException(__('Ovebot.ai connection error: timeout'));
            }
            if (!isset($this->responses[$key])) {
                return new Response(200, []);
            }
            if (!is_array($this->responses[$key])) {
                return $this->responses[$key];
            }
            $next = array_shift($this->responses[$key]);
            if (!$this->responses[$key]) {
                unset($this->responses[$key]);
            }

            return $next;
        });
        $client->method('refreshToken')->willReturnCallback(function ($refreshToken) {
            $this->calls[] = ['key' => 'REFRESH', 'body' => $refreshToken, 'token' => $this->token];
            if ($this->refreshAnswer instanceof \Exception) {
                throw $this->refreshAnswer;
            }

            return $this->refreshAnswer
                ?: ['access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_in' => 3600];
        });
        $client->method('exchangeCode')->willReturnCallback(function ($code, $verifier) {
            $this->calls[] = ['key' => 'EXCHANGE', 'body' => [$code, $verifier], 'token' => $this->token];
            if ($this->exchangeAnswer instanceof \Exception) {
                throw $this->exchangeAnswer;
            }

            return $this->exchangeAnswer;
        });

        $connections = $this->createMock(ConnectionRepository::class);
        $connections->method('save')->willReturnCallback(function ($connection) {
            if ($this->saveFails) {
                throw new CouldNotSaveException(__('not saved'));
            }
            $this->saves++;

            return $connection;
        });
        $connections->method('reload')->willReturnCallback(function () {
            return $this->reloaded ?: $this->connection;
        });

        $storeView = $this->createMock(StoreView::class);
        $storeView->method('getDomain')->willReturn('www.shop-test.ro');
        $storeView->method('getDomainSlug')->willReturn('shop_test_ro');
        $storeView->method('getOauthCallbackUrl')->willReturn('https://www.shop-test.ro/ovebot/oauth/callback/');
        $storeView->method('getFeedUrl')->willReturnCallback(function ($hash) {
            return $this->baseUrl . 'ovebot/feed/index/?hash=' . $hash;
        });
        $storeView->method('getOrdersUrl')->willReturnCallback(function () {
            return $this->baseUrl . 'ovebot/orders/index/';
        });
        $storeView->method('getCurrencyCode')->willReturn('RON');

        $states = $this->createMock(OauthStateStorage::class);
        $states->method('add')->willReturnCallback(function (...$args) {
            $this->states[] = $args;
        });

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturnCallback(function ($name, $timeout) {
            $this->locks[] = 'lock ' . $name . ' ' . $timeout;

            return $this->lockAvailable;
        });
        $lockManager->method('unlock')->willReturnCallback(function ($name) {
            $this->locks[] = 'unlock ' . $name;

            return true;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->logged[] = (string) $message;
        });

        $rateLimits = $this->createMock(RateLimitResource::class);
        $rateLimits->method('clearAll')->willReturnCallback(function () {
            if ($this->rateLimitFails) {
                throw new \RuntimeException('table is missing');
            }
            $this->rateLimitClears++;

            return 3;
        });

        $widgetCache = $this->createMock(WidgetCache::class);
        $widgetCache->method('clean')->willReturnCallback(function () {
            $this->widgetCleans++;

            return true;
        });

        return new Integration(
            $this->connection,
            $connections,
            $client,
            new ErrorParser(),
            $storeView,
            $states,
            $lockManager,
            new Random(),
            new SetupPayloadBuilder(),
            $rateLimits,
            new OptionsBuilder(),
            $widgetCache,
            $logger
        );
    }

    /**
     * The widget settings as the settings form sends them, cleaned
     *
     * @param array $values
     * @return array
     */
    private function widget(array $values = []): array
    {
        $settings = new WidgetSettings();

        return $settings->sanitize($values);
    }

    private function keys(): array
    {
        return array_map(function ($call) {
            return $call['key'];
        }, $this->calls);
    }

    public function testBeginAuthorization()
    {
        $url = $this->integration()->beginAuthorization('https://www.shop-test.ro/admin_x/ovebot_chat/', 7);

        $this->assertSame(
            'authorize|www.shop-test.ro|https://www.shop-test.ro/ovebot/oauth/callback/|verifier-1|'
            . str_repeat('c', 32),
            $url
        );
        $this->assertCount(1, $this->states);
        $this->assertSame(
            [str_repeat('c', 32), 'verifier-1', 'https://www.shop-test.ro/admin_x/ovebot_chat/', 7],
            array_slice($this->states[0], 0, 4)
        );
    }

    public function testCallbackStoresTokensAndAgentChangeResetsTheSetup()
    {
        $this->connection->setSetupComplete(true)
            ->setKbPageIds([3, 4])
            ->setProductsRecommend(false)
            ->setOrderEnabled(false)
            ->setAgent('');
        $this->exchangeAnswer = [
            'access_token' => 'AT',
            'refresh_token' => 'RT',
            'expires_in' => 3600,
            'workspace' => ['slug' => 'my-shop'],
            'agent' => null,
        ];
        $this->responses['GET /v1/me'] = new Response(200, ['agent' => ['public_id' => 'agent-2']]);

        $integration = $this->integration();

        $this->assertSame(['success' => true], $integration->handleCallback('code', 'ver'));
        $this->assertSame(['EXCHANGE', 'GET /v1/me'], $this->keys());
        $this->assertSame(['code', 'ver'], $this->calls[0]['body']);
        $this->assertSame('AT', $this->calls[1]['token'], '/v1/me is called with the new token');

        $this->assertSame('AT', $this->connection->getAccessToken());
        $this->assertSame('RT', $this->connection->getRefreshToken());
        $this->assertSame('my-shop', $this->connection->getWorkspace());
        $this->assertGreaterThan(time() + 3500, $this->connection->getTokenExpires());
        $this->assertTrue($integration->isConnected());

        $this->assertSame('agent-2', $integration->getAgent());
        $this->assertFalse($this->connection->isSetupComplete());
        $this->assertSame([], $this->connection->getKbPageIds());
        $this->assertTrue($this->connection->isProductsRecommend(), 'the mirrored switches are not set again');
        $this->assertTrue($this->connection->isOrderEnabled());
        $this->assertNull($this->connection->getData('products_recommend'));

        $this->assertSame('https://my-shop.ovebot.ai/setup', $integration->getAgentSetupUrl(), 'no agent in the link');
        $this->assertSame('https://my-shop.ovebot.ai/knowledge-base/5/edit', $integration->getKbEditUrl(5));
        $this->assertSame('my-shop:agent-2', $integration->getConnectionLabel());
        $this->assertSame('/v1/workspaces/my-shop/agents/agent-2', $integration->getAgentApiPath());
    }

    public function testCallbackOnTheSameAgentKeepsTheSetup()
    {
        $this->connect($this->connection)->setAgent('agent-2')->setSetupComplete(true)->setKbPageIds([3]);
        $this->exchangeAnswer = ['access_token' => 'AT', 'refresh_token' => 'RT', 'workspace' => ['slug' => 'my-shop']];
        $this->responses['GET /v1/me'] = new Response(200, ['agent' => 'agent-2']);

        $integration = $this->integration();
        $integration->handleCallback('code', 'ver');

        $this->assertTrue($integration->isSetupComplete());
        $this->assertSame([3], $this->connection->getKbPageIds());
    }

    public function testCallbackWithoutAgentKeyInMeChangesNothing()
    {
        $this->connect($this->connection)->setAgent('agent-2')->setSetupComplete(true);
        $this->exchangeAnswer = ['access_token' => 'AT', 'refresh_token' => 'RT', 'workspace' => ['slug' => 'my-shop']];
        $this->responses['GET /v1/me'] = new Response(200, ['user' => 'x']);

        $integration = $this->integration();
        $integration->handleCallback('code', 'ver');

        $this->assertSame('agent-2', $integration->getAgent());
        $this->assertTrue($integration->isSetupComplete());
    }

    public function testDefaultAgent()
    {
        $this->exchangeAnswer = ['access_token' => 'AT', 'refresh_token' => 'RT', 'workspace' => ['slug' => 'my-shop']];
        $this->responses['GET /v1/me'] = new Response(200, ['agent' => 'default']);

        $integration = $this->integration();
        $integration->handleCallback('code', 'ver');

        $this->assertSame('', $integration->getAgent());
        $this->assertSame('default', $integration->getAgentForApi());
        $this->assertSame('my-shop:default', $integration->getConnectionLabel());
        $this->assertSame('https://my-shop.ovebot.ai/setup', $integration->getAgentSetupUrl());
        $this->assertSame('https://my-shop.ovebot.ai/knowledge-base', $integration->getAccountUrl('/knowledge-base'));
    }

    public function testInvalidWorkspaceIsNotStored()
    {
        $this->exchangeAnswer = [
            'access_token' => 'AT',
            'refresh_token' => 'RT',
            'workspace' => ['slug' => 'bad.slug/x'],
        ];

        $integration = $this->integration();
        $integration->handleCallback('c', 'v');

        $this->assertSame('', $this->connection->getWorkspace());
        $this->assertFalse($integration->isConnected());
        $this->assertSame('', $integration->getAccountUrl());
    }

    public function testExchangeFailureGivesTheMessage()
    {
        $this->exchangeAnswer = new AuthException(__('invalid_grant: expired'));

        $integration = $this->integration();

        $this->assertSame(['error' => 'invalid_grant: expired'], $integration->handleCallback('c', 'v'));
        $this->assertSame(0, $this->saves);
        $this->assertFalse($integration->isConnected());
    }

    public function testCallbackReportsWhenTheConnectionCannotBeSaved()
    {
        $this->exchangeAnswer = ['access_token' => 'AT', 'refresh_token' => 'RT', 'workspace' => ['slug' => 'my-shop']];
        $this->saveFails = true;

        $result = $this->integration()->handleCallback('c', 'v');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(['EXCHANGE'], $this->keys());
    }

    public function testOauthErrorIsReadOnce()
    {
        $integration = $this->integration();

        $integration->flashOauthResult(['error' => 'boom']);
        $this->assertSame('boom', $integration->pullOauthError());
        $this->assertSame('', $integration->pullOauthError());

        $integration->flashOauthResult(['success' => true]);
        $this->assertSame('', $integration->pullOauthError());
    }

    public function testOauthErrorIsCleanedUp()
    {
        $integration = $this->integration();

        $integration->flashOauthResult(
            ['error' => " <b>access</b>\n_denied <script>alert(1)</script> " . str_repeat('x', 400)]
        );
        $error = $integration->pullOauthError();

        $this->assertStringStartsWith('access _denied alert(1) xxx', $error);
        $this->assertSame(Integration::FLASH_MAX_LENGTH, mb_strlen($error));
    }

    public function testReactiveRefreshOn401()
    {
        $this->connect($this->connection);
        $this->responses['GET /v1/integration/status'] = [
            new Response(401, []),
            new Response(
                200,
                ['integration' => ['products' => false, 'order_info' => true, 'counts' => ['products' => 12]]]
            ),
        ];

        $integration = $this->integration();

        $this->assertSame(Integration::PROBE_LIVE, $integration->probeConnection());
        $this->assertSame(['GET /v1/integration/status', 'REFRESH', 'GET /v1/integration/status'], $this->keys());
        $this->assertSame('RT1', $this->calls[1]['body']);
        $this->assertSame('AT1', $this->calls[0]['token']);
        $this->assertSame('AT2', $this->calls[2]['token'], 'the retry uses the new token');
        $this->assertSame('AT2', $this->connection->getAccessToken());
        $this->assertSame('RT2', $this->connection->getRefreshToken());
        $this->assertSame(
            ['lock ovebot_chat_refresh 10', 'unlock ovebot_chat_refresh'],
            $this->locks
        );

        $this->assertSame(12, $integration->getIndexedProductCount());
        $integration->syncSettings();
        $this->assertFalse($this->connection->isProductsRecommend());
        $this->assertTrue($this->connection->isOrderEnabled());
        $this->assertSame(0, $this->connection->getData('products_recommend'));

        $this->assertSame(Integration::PROBE_LIVE, $integration->probeConnection());
        $this->assertCount(3, $this->calls, 'the probe runs once per request');
    }

    public function testProactiveRefreshNearExpiry()
    {
        $this->connect($this->connection);
        $this->connection->setTokens('AT1', 'RT1', time() + 60);

        $this->integration()->apiRequest('GET', '/v1/me');

        $this->assertSame(['REFRESH', 'GET /v1/me'], $this->keys());
        $this->assertSame('AT2', $this->calls[1]['token']);
    }

    public function testNoRefreshWhileTheTokenIsFresh()
    {
        $this->connect($this->connection);

        $this->integration()->apiRequest('GET', '/v1/me');

        $this->assertSame(['GET /v1/me'], $this->keys());
        $this->assertSame([], $this->locks);
    }

    public function testRefusedRefreshDropsTheTokensAndKeepsTheWidgetData()
    {
        $this->connect($this->connection)->setChatEnabled(true)->setSetupComplete(true);
        $this->responses['GET /v1/integration/status'] = new Response(401, []);
        $this->refreshAnswer = new AuthException(__('invalid_grant'));

        $integration = $this->integration();

        $this->assertSame(Integration::PROBE_REVOKED, $integration->probeConnection());
        $this->assertSame(['GET /v1/integration/status', 'REFRESH'], $this->keys());
        $this->assertSame('', $this->connection->getAccessToken());
        $this->assertSame('', $this->connection->getRefreshToken());
        $this->assertSame('', $this->token);
        $this->assertFalse($integration->isConnected());
        $this->assertFalse($integration->isSetupComplete(), 'the page goes back to the first step');

        // the storefront widget needs these and keeps working until the merchant connects again
        $this->assertSame('my-shop', $this->connection->getWorkspace());
        $this->assertTrue($this->connection->isChatEnabled());
        $this->assertTrue($this->connection->isSetupComplete());
        $this->assertContains('unlock ovebot_chat_refresh', $this->locks);
    }

    public function testNetworkErrorOnRefreshKeepsTheTokens()
    {
        $this->connect($this->connection);
        $this->responses['GET /v1/integration/status'] = new Response(401, []);
        $this->refreshAnswer = new ConnectionException(__('timeout'));

        $integration = $this->integration();

        $this->assertSame(Integration::PROBE_UNREACHABLE, $integration->probeConnection());
        $this->assertTrue($integration->isConnected());
        $this->assertSame('RT1', $this->connection->getRefreshToken());
    }

    public function testServerErrorIsUnreachableAndChangesNothing()
    {
        $this->connect($this->connection);
        $this->responses['GET /v1/integration/status'] = new Response(502, []);

        $integration = $this->integration();

        $this->assertSame(Integration::PROBE_UNREACHABLE, $integration->probeConnection());
        $this->assertSame('RT1', $this->connection->getRefreshToken());
        $this->assertSame([], $integration->getIntegration());
        $this->assertSame(0, $integration->getIndexedProductCount());

        $integration->syncSettings();
        $this->assertTrue($this->connection->isProductsRecommend());
        $this->assertSame(0, $this->saves);
        $this->assertSame(['GET /v1/integration/status -> HTTP 502 HTTP 502'], $this->logged);
    }

    public function testConnectionExceptionIsUnreachable()
    {
        $this->connect($this->connection);
        $this->throwConnection = true;

        $this->assertSame(Integration::PROBE_UNREACHABLE, $this->integration()->probeConnection());
    }

    public function testNotConnectedIsNotProbed()
    {
        $integration = $this->integration();

        $this->assertSame(Integration::PROBE_DISCONNECTED, $integration->probeConnection());
        $this->assertSame([], $this->calls);
    }

    public function testTokensRotatedByAnotherProcessAreAdopted()
    {
        $this->connect($this->connection);
        $this->reloaded = $this->connect($this->newConnection(), 'AT-OTHER', 'RT-OTHER');
        $this->responses['GET /v1/me'] = [new Response(401, []), new Response(200, [])];

        $integration = $this->integration();
        $response = $integration->apiRequest('GET', '/v1/me');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['GET /v1/me', 'GET /v1/me'], $this->keys(), 'no second refresh with the old token');
        $this->assertSame('AT-OTHER', $this->calls[1]['token']);
        $this->assertSame('RT-OTHER', $integration->getConnection()->getRefreshToken());
    }

    public function testTokensDroppedByAnotherProcessAreNotRefreshed()
    {
        $this->connect($this->connection);
        $this->reloaded = $this->connect($this->newConnection())->clearTokens();
        $this->responses['GET /v1/me'] = new Response(401, []);

        $integration = $this->integration();
        $response = $integration->apiRequest('GET', '/v1/me');

        $this->assertSame(401, $response->getStatus());
        $this->assertSame(['GET /v1/me'], $this->keys());
        $this->assertFalse($integration->isConnected());
    }

    public function testRefreshStillRunsWithoutTheLock()
    {
        $this->connect($this->connection);
        $this->lockAvailable = false;
        $this->responses['GET /v1/me'] = [new Response(401, []), new Response(200, [])];

        $this->integration()->apiRequest('GET', '/v1/me');

        $this->assertSame(['GET /v1/me', 'REFRESH', 'GET /v1/me'], $this->keys());
        $this->assertSame(['lock ovebot_chat_refresh 10'], $this->locks, 'a lock that was not taken is not released');
    }

    public function testRefreshAnswerWithoutRefreshTokenKeepsTheCurrentOne()
    {
        $this->connect($this->connection);
        $this->connection->setTokens('AT1', 'RT1', time() + 60);
        $this->refreshAnswer = ['access_token' => 'AT2'];

        $this->integration()->apiRequest('GET', '/v1/me');

        $this->assertSame('AT2', $this->connection->getAccessToken());
        $this->assertSame('RT1', $this->connection->getRefreshToken());
        $this->assertGreaterThan(time() + 3500, $this->connection->getTokenExpires());
    }

    public function testLogHasNoQueryStringAndNoToken()
    {
        $this->connect($this->connection);
        $this->responses['GET /v1/x'] = new Response(422, ['message' => 'Bad slug']);

        $this->integration()->apiRequest('GET', '/v1/x?email=client@example.com');

        $this->assertSame(['GET /v1/x -> HTTP 422 Bad slug'], $this->logged);
    }

    public function testDisconnectKeepsTheRest()
    {
        $this->connect($this->connection)->setSetupComplete(true)->setAgent('ag')->setKbPageIds([1]);

        $integration = $this->integration();
        $integration->probeConnection();
        $integration->disconnect();

        $this->assertSame(['GET /v1/integration/status', 'POST /v1/disconnect'], $this->keys());
        $this->assertSame('AT1', $this->calls[1]['token']);
        $this->assertSame('', $this->connection->getAccessToken());
        $this->assertSame('', $this->connection->getRefreshToken());
        $this->assertSame(0, $this->connection->getTokenExpires());
        $this->assertSame('', $this->connection->getWorkspace());
        $this->assertSame('', $this->token);

        $this->assertTrue($this->connection->isSetupComplete());
        $this->assertSame('ag', $this->connection->getAgent());
        $this->assertSame([1], $this->connection->getKbPageIds());
        $this->assertSame('my-shop', $this->connection->getLastWorkspace(), 'kept for the next connection');

        $this->assertFalse($integration->isSetupComplete());
        $this->assertSame(Integration::PROBE_DISCONNECTED, $integration->probeConnection());
    }

    public function testDisconnectClosesTheFeedAndTheOrderEndpoint()
    {
        // the account that is left knows the feed URL and the credentials: they must stop working
        $this->connect($this->connection)->setSetupComplete(true)->setChatEnabled(true);
        $integration = $this->integration();
        $integration->resyncSetup();
        $this->assertNotSame([], $this->connection->getLastPush());

        $integration->disconnect();

        $this->assertSame('', $this->connection->getFeedHash());
        $this->assertSame('', $this->connection->getOrderUser());
        $this->assertSame('', $this->connection->getOrderPass());
        $this->assertSame([], $this->connection->getLastPush());
        $this->assertTrue($this->connection->isChatEnabled(), 'the switches stay as they were');

        // the module page makes new ones when it is opened next
        $integration->ensureCredentials();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->connection->getFeedHash());
        $this->assertNotSame(str_repeat('a', 32), $this->connection->getFeedHash());
        $this->assertMatchesRegularExpression('/^shop_test_ro_[a-f0-9]{8}$/', $this->connection->getOrderUser());
        $this->assertNotSame('shop_test_ro_deadbeef', $this->connection->getOrderUser());
        $this->assertNotSame(str_repeat('b', 32), $this->connection->getOrderPass());
    }

    public function testConnectingAgainToTheSameWorkspaceSendsTheNewKeys()
    {
        $this->connect($this->connection)->setSetupComplete(true);
        $integration = $this->integration();
        $integration->resyncSetup();
        $integration->disconnect();
        $integration->ensureCredentials();
        $hash = $this->connection->getFeedHash();

        $this->exchangeAnswer = ['access_token' => 'AT', 'refresh_token' => 'RT', 'workspace' => ['slug' => 'my-shop']];
        $this->responses['GET /v1/me'] = new Response(200, ['agent' => null]);
        $integration->handleCallback('code', 'ver');

        $this->assertTrue($integration->isSetupComplete(), 'same workspace, same agent: straight to the dashboard');

        $this->calls = [];
        $integration->selfHealEndpoints();

        $setup = 'PUT /v1/workspaces/my-shop/agents/default/setup';
        $this->assertSame(['GET /v1/integration/status', $setup], $this->keys());
        $sent = $this->calls[1]['body'];
        $this->assertSame($this->baseUrl . 'ovebot/feed/index/?hash=' . $hash, $sent['products']['feed_url']);
        $this->assertSame($this->connection->getOrderUser(), $sent['order_info']['api_user']);
        $this->assertSame($this->connection->getOrderPass(), $sent['order_info']['api_password']);
    }

    public function testConnectingToAnotherWorkspaceOnTheSameAgentStartsTheWizardAgain()
    {
        // the default agent of another account: the agent did not change, the account did
        $this->connect($this->connection)
            ->setSetupComplete(true)
            ->setKbPageIds([3, 4])
            ->setProductsRecommend(false)
            ->setOrderEnabled(false);
        $integration = $this->integration();
        $integration->disconnect();

        $this->exchangeAnswer = [
            'access_token' => 'AT',
            'refresh_token' => 'RT',
            'workspace' => ['slug' => 'other-shop'],
            'agent' => null,
        ];
        $this->responses['GET /v1/me'] = new Response(200, ['agent' => null]);
        $integration->handleCallback('code', 'ver');

        $this->assertSame('other-shop', $this->connection->getWorkspace());
        $this->assertTrue($integration->isConnected());
        $this->assertFalse($this->connection->isSetupComplete());
        $this->assertSame([], $this->connection->getKbPageIds());
        $this->assertNull($this->connection->getData('products_recommend'));
        $this->assertNull($this->connection->getData('order_enabled'));
    }

    public function testAnotherWorkspaceIsSeenWithoutADisconnectAndWithoutMe()
    {
        // tokens dropped by a refused refresh leave the workspace in place; /v1/me cannot be read
        $this->connect($this->connection)->setSetupComplete(true)->clearTokens();
        $this->exchangeAnswer = [
            'access_token' => 'AT',
            'refresh_token' => 'RT',
            'workspace' => ['slug' => 'other-shop'],
        ];
        $this->responses['GET /v1/me'] = new Response(500, []);

        $this->integration()->handleCallback('code', 'ver');

        $this->assertSame('other-shop', $this->connection->getWorkspace());
        $this->assertFalse($this->connection->isSetupComplete());
    }

    public function testFirstConnectionIsNotAChangeOfWorkspace()
    {
        $this->connection->setSetupComplete(true);
        $this->exchangeAnswer = ['access_token' => 'AT', 'refresh_token' => 'RT', 'workspace' => ['slug' => 'my-shop']];
        $this->responses['GET /v1/me'] = new Response(200, ['agent' => null]);

        $this->integration()->handleCallback('code', 'ver');

        $this->assertTrue($this->connection->isSetupComplete());
    }

    public function testDisconnectCleansUpEvenWhenOvebotCannotBeReached()
    {
        $this->connect($this->connection);
        $this->throwConnection = true;

        $integration = $this->integration();
        $integration->disconnectQuietly(5);

        $this->assertSame(['POST /v1/disconnect'], $this->keys());
        $this->assertFalse($integration->isConnected());
        $this->assertSame('', $this->connection->getWorkspace());
    }

    public function testDisconnectWithoutTokensCallsNothing()
    {
        $this->integration()->disconnect();

        $this->assertSame([], $this->calls);
    }

    public function testEnsureCredentialsSeedsOnce()
    {
        $integration = $this->integration();
        $integration->ensureCredentials();

        $hash = $this->connection->getFeedHash();
        $user = $this->connection->getOrderUser();
        $pass = $this->connection->getOrderPass();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash);
        $this->assertMatchesRegularExpression('/^shop_test_ro_[a-f0-9]{8}$/', $user);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $pass);
        $this->assertNotSame($hash, $pass);
        $this->assertSame(1, $this->saves);
        $this->assertSame([], $this->calls, 'nothing is sent to Ovebot.ai');

        $integration->ensureCredentials();

        $this->assertSame($hash, $this->connection->getFeedHash());
        $this->assertSame($user, $this->connection->getOrderUser());
        $this->assertSame($pass, $this->connection->getOrderPass());
        $this->assertSame(1, $this->saves);
    }

    public function testEnsureCredentialsCompletesWhatIsMissing()
    {
        $this->connection->setFeedHash(str_repeat('a', 32))->setOrderCredentials('shop_test_ro_deadbeef', '');

        $this->integration()->ensureCredentials();

        $this->assertSame(str_repeat('a', 32), $this->connection->getFeedHash());
        $this->assertSame('shop_test_ro_deadbeef', $this->connection->getOrderUser());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->connection->getOrderPass());
    }

    public function testRegisterUrl()
    {
        $this->assertSame('register|wp-freemium|www.shop-test.ro', $this->integration()->getRegisterUrl());
    }

    public function testKbPathsFollowTheAgent()
    {
        $this->connect($this->connection);
        $integration = $this->integration();

        $this->assertSame('/v1/workspaces/my-shop/agents/default/knowledge-base', $integration->getKbApiPath());
        $this->assertSame('https://my-shop.ovebot.ai/knowledge-base/2/edit', $integration->getKbEditUrl(2));

        $this->connection->setAgent('agent 2');

        $this->assertSame('/v1/workspaces/my-shop/agents/agent%202/knowledge-base', $integration->getKbApiPath());
    }

    public function testKbEditUrlWithoutWorkspace()
    {
        $this->assertSame('', $this->integration()->getKbEditUrl(2));
    }

    public function testKbPageSelectionIsSaved()
    {
        $integration = $this->integration();

        $this->assertSame([], $integration->getKbPageIds());
        $this->assertTrue($integration->saveKbPageIds(['4', 2, 4, 0, 'x', -1]));
        $this->assertSame([4, 2], $integration->getKbPageIds());
        $this->assertSame(1, $this->saves);

        $this->saveFails = true;

        $this->assertFalse($integration->saveKbPageIds([]));
    }

    public function testKbEntriesAreReadPageByPage()
    {
        $this->connect($this->connection);
        $key = 'GET /v1/workspaces/my-shop/agents/default/knowledge-base';
        $this->responses[$key] = [
            new Response(200, [
                'entries' => [['id' => 1, 'title' => 'About', 'is_active' => true, 'slug' => 'cms-1']],
                'total' => 3,
            ]),
            new Response(200, [
                'entries' => [['id' => 2, 'title' => ['x'], 'is_active' => 0], ['title' => 'No id']],
                'total' => 3,
            ]),
        ];

        $result = $this->integration()->getKbEntries();

        $this->assertFalse($result['error']);
        $this->assertSame(
            [
                [
                    'id' => 1,
                    'title' => 'About',
                    'is_active' => true,
                    'edit_url' => 'https://my-shop.ovebot.ai/knowledge-base/1/edit',
                ],
                [
                    'id' => 2,
                    'title' => '',
                    'is_active' => false,
                    'edit_url' => 'https://my-shop.ovebot.ai/knowledge-base/2/edit',
                ],
            ],
            $result['entries']
        );
        $this->assertSame([$key, $key], $this->keys());
    }

    public function testKbEntriesThatCannotBeRead()
    {
        $this->connect($this->connection);
        $this->responses['GET /v1/workspaces/my-shop/agents/default/knowledge-base'] = new Response(500, []);

        $this->assertSame(['entries' => [], 'error' => true], $this->integration()->getKbEntries());

        $this->throwConnection = true;

        $this->assertSame(['entries' => [], 'error' => true], $this->integration()->getKbEntries());
    }

    public function testKbEntriesWithoutConnection()
    {
        $this->assertSame(['entries' => [], 'error' => false], $this->integration()->getKbEntries());
        $this->assertSame([], $this->calls);
    }

    public function testSetupIsSentAndTheUrlsAreRemembered()
    {
        $this->connect($this->connection);

        $result = $this->integration()->resyncSetup();

        $this->assertTrue($result['success']);
        $this->assertSame('', $result['error']);
        $this->assertSame(['PUT /v1/workspaces/my-shop/agents/default/setup'], $this->keys());
        $this->assertSame($result['payload'], $this->calls[0]['body']);
        $this->assertSame(
            [
                'widget' => ['language' => 'auto'],
                'order_info' => [
                    'enabled' => true,
                    'api_url' => 'https://www.shop-test.ro/ovebot/orders/index/',
                    'api_user' => 'shop_test_ro_deadbeef',
                    'api_password' => str_repeat('b', 32),
                    'lookup_method' => 'email',
                ],
                'products' => [
                    'enabled' => true,
                    'feed_url' => 'https://www.shop-test.ro/ovebot/feed/index/?hash=' . str_repeat('a', 32),
                    'currency' => 'RON',
                ],
            ],
            $result['payload']
        );
        $this->assertSame(
            [
                'feed_url' => 'https://www.shop-test.ro/ovebot/feed/index/?hash=' . str_repeat('a', 32),
                'api_url' => 'https://www.shop-test.ro/ovebot/orders/index/',
            ],
            $this->connection->getLastPush()
        );
        $this->assertSame(1, $this->saves);
    }

    public function testSetupOfANamedAgent()
    {
        $this->connect($this->connection)->setAgent('agent-7');

        $this->integration()->resyncSetup();

        $this->assertSame(['PUT /v1/workspaces/my-shop/agents/agent-7/setup'], $this->keys());
    }

    public function testSetupOverridesAreSentButNotStored()
    {
        $this->connect($this->connection);

        $result = $this->integration()->resyncSetup(['products_builtin' => false, 'order_enabled' => false]);

        $this->assertTrue($result['success']);
        $this->assertSame(['enabled' => true], $result['payload']['products']);
        $this->assertFalse($result['payload']['order_info']['enabled']);
        $this->assertTrue($this->connection->isProductsBuiltin());
        $this->assertTrue($this->connection->isOrderEnabled());
        // without a feed URL in the payload, an empty one is remembered
        $this->assertSame('', $this->connection->getLastPush()['feed_url']);
    }

    public function testRefusedSetupKeepsTheLastUrls()
    {
        $this->connect($this->connection)->setLastPush(['feed_url' => 'old-feed', 'api_url' => 'old-api']);
        $this->responses['PUT /v1/workspaces/my-shop/agents/default/setup'] = new Response(
            422,
            ['message' => 'The api url field is required.']
        );

        $result = $this->integration()->resyncSetup();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The api url field is required.', $result['error']);
        $this->assertSame(['feed_url' => 'old-feed', 'api_url' => 'old-api'], $this->connection->getLastPush());
        $this->assertSame(0, $this->saves);
    }

    public function testSetupWhenOvebotCannotBeReached()
    {
        $this->connect($this->connection);
        $this->throwConnection = true;

        $result = $this->integration()->resyncSetup();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('timeout', $result['error']);
        $this->assertSame([], $this->connection->getLastPush());
    }

    public function testSetupIsNotSentWithoutConnection()
    {
        $result = $this->integration()->resyncSetup();

        $this->assertFalse($result['success']);
        $this->assertNotSame('', $result['error']);
        $this->assertSame([], $this->calls);
    }

    public function testFinishMarksTheSetupAndSwitchesTheChatOn()
    {
        $this->connect($this->connection);

        $result = $this->integration()->finish(true);

        $this->assertSame(['success' => true, 'error' => ''], $result);
        $this->assertSame(['PUT /v1/workspaces/my-shop/agents/default/setup'], $this->keys());
        $this->assertTrue($this->connection->isSetupComplete());
        $this->assertTrue($this->connection->isChatEnabled());
        $this->assertTrue($this->connection->isProductsBuiltin());
        $this->assertSame('1', (string) $this->connection->getData('products_recommend'));
        $this->assertSame('1', (string) $this->connection->getData('order_enabled'));
        $this->assertArrayHasKey('feed_url', $this->calls[0]['body']['products']);
    }

    public function testFinishWithTheMerchantsOwnFeed()
    {
        $this->connect($this->connection);

        $result = $this->integration()->finish(false);

        $this->assertTrue($result['success']);
        $this->assertFalse($this->connection->isProductsBuiltin());
        $this->assertSame(['enabled' => true], $this->calls[0]['body']['products']);
        $this->assertTrue($this->connection->isSetupComplete());
    }

    public function testFinishSwitchesRecommendationsAndOrderTrackingOn()
    {
        $this->connect($this->connection)->setProductsRecommend(false)->setOrderEnabled(false);

        $this->assertTrue($this->integration()->finish(true)['success']);

        $body = $this->calls[0]['body'];
        $this->assertTrue($body['products']['enabled']);
        $this->assertArrayHasKey('feed_url', $body['products']);
        $this->assertTrue($body['order_info']['enabled']);
        $this->assertTrue($this->connection->isProductsRecommend());
        $this->assertTrue($this->connection->isOrderEnabled());
    }

    public function testRefusedFinishDoesNotStoreTheSwitches()
    {
        $this->connect($this->connection)->setProductsRecommend(false)->setOrderEnabled(false);
        $this->responses['PUT /v1/workspaces/my-shop/agents/default/setup'] = new Response(
            500,
            ['message' => 'Server error']
        );

        $this->assertFalse($this->integration()->finish(true)['success']);

        $this->assertTrue($this->calls[0]['body']['order_info']['enabled']);
        $this->assertFalse($this->connection->isProductsRecommend());
        $this->assertFalse($this->connection->isOrderEnabled());
    }

    public function testWizardForAnotherAgentWithBothFeaturesOffInTheAccount()
    {
        // finished for the default agent, then connected to another agent, which has both features off
        $this->live()->setAgent('')->setProductsRecommend(true)->setOrderEnabled(true);
        $this->exchangeAnswer = ['access_token' => 'AT', 'refresh_token' => 'RT', 'workspace' => ['slug' => 'my-shop']];
        $this->responses['GET /v1/me'] = new Response(200, ['agent' => 'agent-2']);
        $this->responses['GET /v1/integration/status'] = new Response(
            200,
            ['integration' => ['products' => false, 'order_info' => false]]
        );
        $this->integration()->handleCallback('code', 'ver');

        // every page load of the wizard aligns the switches with the account
        $integration = $this->integration();
        $integration->syncSettings();
        $this->assertFalse($this->connection->isProductsRecommend());

        $this->assertTrue($integration->finish(true)['success']);

        $body = $this->calls[count($this->calls) - 1]['body'];
        $this->assertTrue($body['products']['enabled']);
        $this->assertTrue($body['order_info']['enabled']);
        $this->assertTrue($this->connection->isProductsRecommend());
        $this->assertTrue($this->connection->isOrderEnabled());
    }

    public function testRefusedFinishKeepsTheChoiceAndLeavesTheSetupOpen()
    {
        $this->connect($this->connection);
        $this->responses['PUT /v1/workspaces/my-shop/agents/default/setup'] = new Response(
            500,
            ['message' => 'Server error']
        );

        $result = $this->integration()->finish(false);

        $this->assertFalse($result['success']);
        $this->assertNotSame('', $result['error']);
        $this->assertFalse($this->connection->isSetupComplete());
        $this->assertFalse($this->connection->isChatEnabled());
        // the choice of step 3 is kept for the next try
        $this->assertFalse($this->connection->isProductsBuiltin());
        $this->assertSame(1, $this->saves);
    }

    public function testFinishWithoutConnectionCallsNothing()
    {
        $result = $this->integration()->finish(true);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, $this->saves);
        $this->assertFalse($this->connection->isSetupComplete());
    }

    public function testFinishThatCannotBeSavedCallsNothing()
    {
        $this->connect($this->connection);
        $this->saveFails = true;

        $result = $this->integration()->finish(true);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->calls);
    }

    public function testFinishAfterTheTokensWereReadAgain()
    {
        // the access token is refused once; under the lock the connection is read again from the database
        $this->connect($this->connection);
        $this->reloaded = $this->connect($this->newConnection());
        $key = 'PUT /v1/workspaces/my-shop/agents/default/setup';
        $this->responses[$key] = [new Response(401, []), new Response(200, [])];

        $integration = $this->integration();
        $result = $integration->finish(true);

        $this->assertTrue($result['success']);
        $this->assertSame([$key, 'REFRESH', $key], $this->keys());
        $this->assertSame($this->reloaded, $integration->getConnection());
        $this->assertTrue($integration->getConnection()->isSetupComplete());
        $this->assertTrue($integration->getConnection()->isChatEnabled());
        $this->assertNotSame([], $integration->getConnection()->getLastPush());
    }

    public function testEndpointsAreSentAgainWhenTheUrlsChanged()
    {
        $this->connect($this->connection)->setSetupComplete(true);
        $integration = $this->integration();
        $integration->resyncSetup();
        $this->calls = [];

        $this->baseUrl = 'https://shop-test.ro/';
        $integration->selfHealEndpoints();

        $this->assertSame(
            ['GET /v1/integration/status', 'PUT /v1/workspaces/my-shop/agents/default/setup'],
            $this->keys()
        );
        $this->assertSame('https://shop-test.ro/ovebot/orders/index/', $this->connection->getLastPush()['api_url']);
    }

    public function testEndpointsAreLeftAloneWhileTheUrlsAreTheSame()
    {
        $this->connect($this->connection)->setSetupComplete(true);
        $integration = $this->integration();
        $integration->resyncSetup();
        $this->calls = [];

        $integration->selfHealEndpoints();

        $this->assertSame(['GET /v1/integration/status'], $this->keys());
    }

    public function testEndpointsWithOwnFeedCompareAnEmptyFeedUrl()
    {
        $this->connect($this->connection)->setSetupComplete(true)->setProductsBuiltin(false);
        $integration = $this->integration();
        $integration->resyncSetup();
        $this->calls = [];

        $integration->selfHealEndpoints();

        $this->assertSame(['GET /v1/integration/status'], $this->keys());
    }

    public function testEndpointsAreNotSentBeforeTheSetupIsFinished()
    {
        $this->connect($this->connection);

        $this->integration()->selfHealEndpoints();

        $this->assertSame([], $this->calls);
    }

    public function testEndpointsAreNotSentWhileOvebotCannotBeReached()
    {
        $this->connect($this->connection)->setSetupComplete(true);
        $this->responses['GET /v1/integration/status'] = new Response(503, []);

        $this->integration()->selfHealEndpoints();

        $this->assertSame(['GET /v1/integration/status'], $this->keys());
        $this->assertSame([], $this->connection->getLastPush());
    }

    public function testSwitchesNotSetCountAsOn()
    {
        $this->assertSame(
            ['products_builtin' => true, 'products_recommend' => true, 'order_enabled' => true],
            $this->integration()->effectiveSwitches()
        );
    }

    public function testSettingsAreSentAndThenStored()
    {
        $this->connect($this->connection)->setSetupComplete(true)->setChatEnabled(true);

        $result = $this->integration()->saveSettings(
            false,
            $this->widget(['language' => 'ro', 'accent_color' => '#ff0000', 'offset_y' => '35']),
            false,
            true,
            false
        );

        $this->assertSame(Integration::SAVE_OK, $result['status']);
        $this->assertSame('', $result['error']);
        $this->assertSame(
            ['products_builtin' => false, 'products_recommend' => true, 'order_enabled' => false],
            $result['effective']
        );
        $this->assertSame(['PUT /v1/workspaces/my-shop/agents/default/setup'], $this->keys());

        $body = $this->calls[0]['body'];
        $this->assertSame(['language' => 'ro'], $body['widget']);
        $this->assertFalse($body['order_info']['enabled']);
        // recommendations on, feed of the merchant: no feed URL goes out
        $this->assertSame(['enabled' => true], $body['products']);

        $this->assertFalse($this->connection->isChatEnabled());
        $this->assertFalse($this->connection->isProductsBuiltin());
        $this->assertTrue($this->connection->isProductsRecommend());
        $this->assertFalse($this->connection->isOrderEnabled());
        $this->assertSame('1', (string) $this->connection->getData('products_recommend'));

        $widget = $this->connection->getWidget();
        $this->assertSame('#FF0000', $widget['accent_color']);
        $this->assertSame('ro', $widget['language']);
        $this->assertSame('35', $widget['offset_y']);
        $this->assertSame('', $widget['theme']);
    }

    public function testEverySettingIsStoredAndReadBack()
    {
        $this->connect($this->connection)->setSetupComplete(true);
        $widget = [
            'accent_color' => '#0A1B2C',
            'theme' => 'dark',
            'language' => 'de',
            'audio_beep' => 'none',
            'side' => 'left',
            'offset_y' => '40',
            'offset_x' => '15',
            'z_index' => '9000',
            'subtitle' => 'Usually replies in a few minutes',
            'proactive_message' => 'Need help finding something?',
            'proactive_delay' => '12',
        ];

        $result = $this->integration()->saveSettings(true, $this->widget($widget), true, false, true);

        $this->assertSame(Integration::SAVE_OK, $result['status']);
        $this->assertSame($widget, (new WidgetSettings())->withDefaults($this->connection->getWidget()));
        $this->assertTrue($this->connection->isChatEnabled());
        $this->assertSame(
            ['products_builtin' => true, 'products_recommend' => false, 'order_enabled' => true],
            $this->integration()->effectiveSwitches()
        );
    }

    public function testRefusedSettingsKeepTheSwitchesAndStoreTheRest()
    {
        $this->connect($this->connection)->setSetupComplete(true);
        $this->responses['PUT /v1/workspaces/my-shop/agents/default/setup'] = new Response(
            422,
            ['message' => 'The api url field is required.']
        );

        $result = $this->integration()->saveSettings(true, $this->widget(['theme' => 'dark']), false, false, false);

        $this->assertSame(Integration::SAVE_SYNC_FAILED, $result['status']);
        $this->assertStringContainsString('The api url field is required.', $result['error']);
        // the form goes back to what the shop and the account still agree on
        $this->assertSame(
            ['products_builtin' => true, 'products_recommend' => true, 'order_enabled' => true],
            $result['effective']
        );
        $this->assertTrue($this->connection->isProductsBuiltin());
        $this->assertTrue($this->connection->isProductsRecommend());
        $this->assertTrue($this->connection->isOrderEnabled());
        // what stays in the shop was stored before the call
        $this->assertTrue($this->connection->isChatEnabled());
        $this->assertSame('dark', $this->connection->getWidget()['theme']);
        $this->assertSame(1, $this->saves);
    }

    public function testSettingsWhenOvebotCannotBeReached()
    {
        $this->connect($this->connection)->setSetupComplete(true);
        $this->throwConnection = true;

        $result = $this->integration()->saveSettings(true, $this->widget(), true, true, false);

        $this->assertSame(Integration::SAVE_SYNC_FAILED, $result['status']);
        $this->assertStringContainsString('timeout', $result['error']);
        $this->assertTrue($result['effective']['order_enabled']);
        $this->assertTrue($this->connection->isConnected());
    }

    public function testSettingsWithoutConnectionAreKeptForLater()
    {
        $this->connection->setSetupComplete(true);

        $result = $this->integration()->saveSettings(true, $this->widget(['side' => 'left']), false, false, true);

        $this->assertSame(Integration::SAVE_NEEDS_RECONNECT, $result['status']);
        $this->assertSame([], $this->calls);
        $this->assertSame(
            ['products_builtin' => false, 'products_recommend' => false, 'order_enabled' => true],
            $result['effective']
        );
        $this->assertTrue($this->connection->isChatEnabled());
        $this->assertSame('left', $this->connection->getWidget()['side']);
        $this->assertSame(1, $this->saves);
    }

    public function testSettingsThatCannotBeSavedCallNothing()
    {
        $this->connect($this->connection)->setSetupComplete(true);
        $this->saveFails = true;

        $result = $this->integration()->saveSettings(true, $this->widget(), false, false, false);

        $this->assertSame(Integration::SAVE_FAILED, $result['status']);
        $this->assertNotSame('', $result['error']);
        $this->assertSame([], $this->calls);
    }

    public function testSettingsAfterTheTokensWereReadAgain()
    {
        // the access token is refused once; under the lock the connection is read again from the database
        $this->connect($this->connection)->setSetupComplete(true);
        $this->reloaded = $this->connect($this->newConnection())->setSetupComplete(true);
        $key = 'PUT /v1/workspaces/my-shop/agents/default/setup';
        $this->responses[$key] = [new Response(401, []), new Response(200, [])];

        $integration = $this->integration();
        $result = $integration->saveSettings(true, $this->widget(), false, true, false);

        $this->assertSame(Integration::SAVE_OK, $result['status']);
        $this->assertSame([$key, 'REFRESH', $key], $this->keys());
        $this->assertSame($this->reloaded, $integration->getConnection());
        $this->assertFalse($integration->getConnection()->isProductsBuiltin());
        $this->assertTrue($integration->getConnection()->isProductsRecommend());
        $this->assertFalse($integration->getConnection()->isOrderEnabled());
        $this->assertSame(
            ['products_builtin' => false, 'products_recommend' => true, 'order_enabled' => false],
            $result['effective']
        );
    }

    public function testFeedHashIsSentBeforeItIsStored()
    {
        $this->connect($this->connection);

        $result = $this->integration()->regenerateFeedHash();

        $this->assertTrue($result['success']);
        $this->assertTrue($result['synced']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\z/', $result['hash']);
        $this->assertNotSame(str_repeat('a', 32), $result['hash']);
        $this->assertSame('https://www.shop-test.ro/ovebot/feed/index/?hash=' . $result['hash'], $result['url']);
        $this->assertSame(['PUT /v1/workspaces/my-shop/agents/default/setup'], $this->keys());
        $this->assertSame($result['url'], $this->calls[0]['body']['products']['feed_url']);
        $this->assertSame($result['hash'], $this->connection->getFeedHash());
        $this->assertSame($result['url'], $this->connection->getLastPush()['feed_url']);
    }

    public function testRefusedFeedHashKeepsTheOldOne()
    {
        $this->connect($this->connection);
        $this->responses['PUT /v1/workspaces/my-shop/agents/default/setup'] = new Response(500, []);

        $result = $this->integration()->regenerateFeedHash();

        $this->assertFalse($result['success']);
        $this->assertNotSame('', $result['error']);
        $this->assertSame('', $result['hash']);
        $this->assertSame('', $result['url']);
        $this->assertSame(str_repeat('a', 32), $this->connection->getFeedHash());
        $this->assertSame(0, $this->saves);
    }

    public function testFeedHashThatOvebotDoesNotReadStaysInTheShop()
    {
        $this->connect($this->connection)->setProductsBuiltin(false);

        $result = $this->integration()->regenerateFeedHash();

        $this->assertTrue($result['success']);
        $this->assertFalse($result['synced']);
        $this->assertSame([], $this->calls);
        $this->assertSame($result['hash'], $this->connection->getFeedHash());
        $this->assertSame(1, $this->saves);

        $this->connection->setProductsBuiltin(true)->setProductsRecommend(false);
        $this->assertFalse($this->integration()->regenerateFeedHash()['synced']);
        $this->assertSame([], $this->calls);
    }

    public function testFeedHashWithoutConnection()
    {
        $this->connection->setFeedHash(str_repeat('a', 32));

        $result = $this->integration()->regenerateFeedHash();

        $this->assertTrue($result['success']);
        $this->assertFalse($result['synced']);
        $this->assertSame([], $this->calls);
        $this->assertNotSame(str_repeat('a', 32), $this->connection->getFeedHash());
    }

    public function testFeedHashThatCannotBeSaved()
    {
        $this->connection->setFeedHash(str_repeat('a', 32));
        $this->saveFails = true;

        $result = $this->integration()->regenerateFeedHash();

        $this->assertFalse($result['success']);
        $this->assertNotSame('', $result['error']);
        $this->assertSame('', $result['hash']);
    }

    public function testCredentialsAreSentBeforeTheyAreStored()
    {
        $this->connect($this->connection);

        $result = $this->integration()->regenerateOrderCreds();

        $this->assertTrue($result['success']);
        $this->assertTrue($result['synced']);
        $this->assertMatchesRegularExpression('/^shop_test_ro_[0-9a-f]{8}\z/', $result['user']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\z/', $result['pass']);
        $this->assertNotSame('shop_test_ro_deadbeef', $result['user']);
        $this->assertNotSame(str_repeat('b', 32), $result['pass']);
        $this->assertSame(['PUT /v1/workspaces/my-shop/agents/default/setup'], $this->keys());
        $this->assertSame($result['user'], $this->calls[0]['body']['order_info']['api_user']);
        $this->assertSame($result['pass'], $this->calls[0]['body']['order_info']['api_password']);
        $this->assertSame($result['user'], $this->connection->getOrderUser());
        $this->assertSame($result['pass'], $this->connection->getOrderPass());
        // kept encrypted
        $this->assertNotSame($result['pass'], $this->connection->getData('order_pass'));
        $this->assertSame(1, $this->rateLimitClears);
    }

    public function testRefusedCredentialsKeepTheOldOnes()
    {
        $this->connect($this->connection);
        $this->responses['PUT /v1/workspaces/my-shop/agents/default/setup'] = new Response(
            422,
            ['message' => 'The api user field is required.']
        );

        $result = $this->integration()->regenerateOrderCreds();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The api user field is required.', $result['error']);
        $this->assertSame('', $result['user']);
        $this->assertSame('', $result['pass']);
        $this->assertSame('shop_test_ro_deadbeef', $this->connection->getOrderUser());
        $this->assertSame(str_repeat('b', 32), $this->connection->getOrderPass());
        // the old credentials still work, so the blocks they caused stay as well
        $this->assertSame(0, $this->rateLimitClears);
    }

    public function testCredentialsWithoutConnectionStayInTheShop()
    {
        $result = $this->integration()->regenerateOrderCreds();

        $this->assertTrue($result['success']);
        $this->assertFalse($result['synced']);
        $this->assertSame([], $this->calls);
        $this->assertSame($result['user'], $this->connection->getOrderUser());
        $this->assertSame(1, $this->rateLimitClears);
    }

    public function testCredentialsAreKeptWhenTheFailedAttemptsCannotBeForgotten()
    {
        $this->connect($this->connection);
        $this->rateLimitFails = true;

        $result = $this->integration()->regenerateOrderCreds();

        $this->assertTrue($result['success']);
        $this->assertSame($result['user'], $this->connection->getOrderUser());
        $this->assertStringContainsString('Rate limit table not cleared', implode("\n", $this->logged));
        // the log names the kind of error, never a value
        $this->assertStringNotContainsString($result['pass'], implode("\n", $this->logged));
    }

    public function testCredentialsThatCannotBeSaved()
    {
        $this->saveFails = true;

        $result = $this->integration()->regenerateOrderCreds();

        $this->assertFalse($result['success']);
        $this->assertSame('', $result['pass']);
        $this->assertSame(0, $this->rateLimitClears);
    }

    /**
     * A connection whose widget is on the storefront
     *
     * @return Connection
     */
    private function live(): Connection
    {
        return $this->connect($this->connection)
            ->setSetupComplete(true)
            ->setChatEnabled(true)
            ->setWidget($this->widget(['theme' => 'dark']));
    }

    public function testFinishCleansTheWidgetPagesOnce()
    {
        $this->connect($this->connection);

        $this->assertTrue($this->integration()->finish(true)['success']);

        // three saves: the choice of step 3 and the accepted URLs change nothing on the storefront, the finished
        // setup does
        $this->assertSame(3, $this->saves);
        $this->assertSame(1, $this->widgetCleans);
    }

    public function testRefusedFinishLeavesTheWidgetPages()
    {
        $this->connect($this->connection);
        $this->responses['PUT /v1/workspaces/my-shop/agents/default/setup'] = new Response(500, []);

        $this->assertFalse($this->integration()->finish(true)['success']);

        $this->assertSame(0, $this->widgetCleans);
    }

    public function testFinishAfterTheTokensWereReadAgainCleansTheWidgetPages()
    {
        $this->connect($this->connection);
        $this->reloaded = $this->connect($this->newConnection());
        $key = 'PUT /v1/workspaces/my-shop/agents/default/setup';
        $this->responses[$key] = [new Response(401, []), new Response(200, [])];

        $this->assertTrue($this->integration()->finish(true)['success']);

        $this->assertSame(1, $this->widgetCleans);
    }

    public function testNewAppearanceCleansTheWidgetPages()
    {
        $this->live();

        $result = $this->integration()->saveSettings(true, $this->widget(['theme' => 'light']), true, true, true);

        $this->assertSame(Integration::SAVE_OK, $result['status']);
        $this->assertSame(1, $this->widgetCleans);
    }

    public function testSwitchingTheChatOffCleansTheWidgetPages()
    {
        $this->live();

        $this->integration()->saveSettings(false, $this->widget(['theme' => 'dark']), true, true, true);

        $this->assertSame(1, $this->widgetCleans);
    }

    public function testSettingsThatDoNotTouchTheWidgetLeaveTheWidgetPages()
    {
        $this->live();

        $result = $this->integration()->saveSettings(true, $this->widget(['theme' => 'dark']), false, false, false);

        $this->assertSame(Integration::SAVE_OK, $result['status']);
        $this->assertSame(0, $this->widgetCleans);
    }

    public function testAppearanceChangedWhileTheChatIsOffLeavesTheWidgetPages()
    {
        $this->connect($this->connection)->setSetupComplete(true)->setChatEnabled(false);

        $widget = $this->widget(['theme' => 'light', 'side' => 'left']);
        $this->integration()->saveSettings(false, $widget, true, true, true);

        $this->assertSame(0, $this->widgetCleans);
    }

    public function testSettingsThatCannotBeSavedLeaveTheWidgetPages()
    {
        $this->live();
        $this->saveFails = true;

        $result = $this->integration()->saveSettings(false, $this->widget(), true, true, true);

        $this->assertSame(Integration::SAVE_FAILED, $result['status']);
        $this->assertSame(0, $this->widgetCleans);
    }

    public function testDisconnectCleansTheWidgetPages()
    {
        $this->live();

        $this->integration()->disconnect();

        $this->assertSame('', $this->connection->getWorkspace());
        $this->assertSame(1, $this->widgetCleans);
    }

    public function testTokenRefreshAndSwitchSyncLeaveTheWidgetPages()
    {
        $this->live();
        $this->responses['GET /v1/integration/status'] = [
            new Response(401, []),
            new Response(200, ['integration' => ['products' => false, 'order_info' => false]]),
        ];

        $integration = $this->integration();
        $integration->probeConnection();
        $integration->syncSettings();

        $this->assertSame('RT2', $this->connection->getRefreshToken());
        $this->assertFalse($this->connection->isOrderEnabled());
        $this->assertGreaterThan(0, $this->saves);
        $this->assertSame(0, $this->widgetCleans);
    }

    public function testConnectingToAnotherAgentCleansTheWidgetPages()
    {
        $this->live()->setAgent('agent-1');
        $this->exchangeAnswer = ['access_token' => 'AT', 'refresh_token' => 'RT', 'workspace' => ['slug' => 'my-shop']];
        $this->responses['GET /v1/me'] = new Response(200, ['agent' => 'agent-2']);

        $this->integration()->handleCallback('code', 'ver');

        // the wizard starts again for the new agent, so the widget leaves the storefront until it is finished
        $this->assertFalse($this->connection->isSetupComplete());
        $this->assertSame(1, $this->widgetCleans);
    }
}
