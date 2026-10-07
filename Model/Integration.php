<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Lock\LockManagerInterface;
use Ovebot\Chat\Model\Api\Client;
use Ovebot\Chat\Model\Api\ErrorParser;
use Ovebot\Chat\Model\Api\Exception\AuthException;
use Ovebot\Chat\Model\Api\Exception\OvebotException;
use Ovebot\Chat\Model\Api\Response;
use Ovebot\Chat\Model\Cache\WidgetCache;
use Ovebot\Chat\Model\ResourceModel\RateLimit as RateLimitResource;
use Ovebot\Chat\Model\Security\Random;
use Ovebot\Chat\Model\StoreContext\StoreView;
use Ovebot\Chat\Model\Widget\OptionsBuilder;
use Psr\Log\LoggerInterface;

/**
 * Orchestrator of the Ovebot.ai integration of the shop.
 *
 * Owns the storage and the refresh of the tokens and the local state; the HTTP and OAuth calls are made by the
 * Client. One instance per request, so the connection probe runs once (see IntegrationFactory).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class Integration
{
    public const FLASH_TTL = 900;
    public const FLASH_MAX_LENGTH = 300;

    public const PROBE_LIVE = 'live';
    public const PROBE_REVOKED = 'revoked';
    public const PROBE_UNREACHABLE = 'unreachable';
    public const PROBE_DISCONNECTED = 'disconnected';

    public const SAVE_OK = 'ok';
    public const SAVE_NEEDS_RECONNECT = 'needs_reconnect';
    public const SAVE_SYNC_FAILED = 'sync_failed';
    public const SAVE_FAILED = 'save_failed';

    public const KB_PAGE_SIZE = 100;
    public const KB_MAX_PAGES = 50;

    private const REFRESH_MARGIN = 300;
    private const LOCK_TIMEOUT = 10;
    private const DEFAULT_TOKEN_LIFETIME = 3600;
    private const LOCK_NAME = 'ovebot_chat_refresh';

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var ConnectionRepository
     */
    private $connections;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var ErrorParser
     */
    private $errorParser;

    /**
     * @var StoreView
     */
    private $storeView;

    /**
     * @var OauthStateStorage
     */
    private $states;

    /**
     * @var LockManagerInterface
     */
    private $lockManager;

    /**
     * @var Random
     */
    private $random;

    /**
     * @var SetupPayloadBuilder
     */
    private $payloads;

    /**
     * @var RateLimitResource
     */
    private $rateLimits;

    /**
     * @var OptionsBuilder
     */
    private $widgetOptions;

    /**
     * @var WidgetCache
     */
    private $widgetCache;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string what the storefront widget showed at the last read or save of the connection
     */
    private $widgetState;

    /**
     * @var array|null body of GET /v1/integration/status; null = not fetched, [] = failed
     */
    private $statusBody;

    /**
     * @var string|null one of the PROBE_* constants, once probed
     */
    private $probe;

    /**
     * @param Connection $connection
     * @param ConnectionRepository $connections
     * @param Client $client
     * @param ErrorParser $errorParser
     * @param StoreView $storeView
     * @param OauthStateStorage $states
     * @param LockManagerInterface $lockManager
     * @param Random $random
     * @param SetupPayloadBuilder $payloads
     * @param RateLimitResource $rateLimits
     * @param OptionsBuilder $widgetOptions
     * @param WidgetCache $widgetCache
     * @param LoggerInterface $logger
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Connection $connection,
        ConnectionRepository $connections,
        Client $client,
        ErrorParser $errorParser,
        StoreView $storeView,
        OauthStateStorage $states,
        LockManagerInterface $lockManager,
        Random $random,
        SetupPayloadBuilder $payloads,
        RateLimitResource $rateLimits,
        OptionsBuilder $widgetOptions,
        WidgetCache $widgetCache,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->connections = $connections;
        $this->client = $client;
        $this->errorParser = $errorParser;
        $this->storeView = $storeView;
        $this->states = $states;
        $this->lockManager = $lockManager;
        $this->random = $random;
        $this->payloads = $payloads;
        $this->rateLimits = $rateLimits;
        $this->widgetOptions = $widgetOptions;
        $this->widgetCache = $widgetCache;
        $this->logger = $logger;
        $this->widgetState = $widgetOptions->getState($connection);
    }

    /**
     * The stored connection
     *
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * URLs, domain and currency of the storefront of the shop
     *
     * @return StoreView
     */
    public function getStoreView(): StoreView
    {
        return $this->storeView;
    }

    /**
     * Start an authorization: remember the PKCE verifier under a one-time state and build the authorize URL
     *
     * @param string $returnUrl absolute admin URL to come back to
     * @param int $adminUserId
     * @return string the authorize URL
     * @throws \Exception when no random values can be generated or the state cannot be stored
     */
    public function beginAuthorization(string $returnUrl, int $adminUserId): string
    {
        $verifier = $this->client->generateVerifier();
        $state = $this->client->generateState();

        $this->states->add($state, $verifier, $returnUrl, $adminUserId, time());

        return $this->client->buildAuthUrl(
            $this->storeView->getDomain(),
            $this->storeView->getOauthCallbackUrl(),
            $verifier,
            $state
        );
    }

    /**
     * Exchange the authorization code for tokens and store them. Never throws.
     *
     * @param string $code
     * @param string $verifier
     * @return array ['success' => true] or ['error' => message]
     */
    public function handleCallback(string $code, string $verifier): array
    {
        try {
            $tokens = $this->client->exchangeCode($code, $verifier);
        } catch (OvebotException $e) {
            $this->log('OAuth code exchange failed: ' . $e->getMessage());

            return ['error' => $e->getMessage()];
        }

        // Read the old agent and the old workspace BEFORE the tokens are stored: they may bring new ones along.
        // After a disconnect, the old workspace is the one of the connection that was closed.
        $previousAgent = $this->connection->getAgent();
        $previousWorkspace = $this->connection->getWorkspace() !== ''
            ? $this->connection->getWorkspace()
            : $this->connection->getLastWorkspace();

        if (!$this->storeTokens($tokens)) {
            return ['error' => (string) __('The Ovebot.ai connection could not be saved.')];
        }
        $this->syncAgentFromMe($previousAgent, $previousWorkspace);

        return ['success' => true];
    }

    /**
     * Keep the result of the OAuth return, to be shown once on the admin page
     *
     * @param array $result the result of handleCallback()
     * @return void
     */
    public function flashOauthResult(array $result)
    {
        $error = isset($result['error']) && is_scalar($result['error']) ? (string) $result['error'] : '';
        // the text may come from the query string of the callback: one line, no markup, limited length
        $error = trim((string) preg_replace('/\s+/u', ' ', strip_tags($error)));
        $error = mb_substr($error, 0, self::FLASH_MAX_LENGTH);

        $this->connection->setOauthError($error, time() + self::FLASH_TTL);
        $this->persist();
    }

    /**
     * Read the OAuth error once; empty when there is none
     *
     * @return string
     */
    public function pullOauthError(): string
    {
        if ((string) $this->connection->getData('oauth_error') === '') {
            return '';
        }

        $error = $this->connection->getOauthError(time());
        $this->connection->setOauthError('', 0);
        $this->persist();

        return $error;
    }

    /**
     * Every API call goes through here: refresh ahead of expiry, then one refresh and one retry on a 401
     *
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @return Response
     * @throws OvebotException when Ovebot.ai cannot be reached
     */
    public function apiRequest(string $method, string $path, ?array $body = null): Response
    {
        $expires = $this->connection->getTokenExpires();
        if ($expires && $expires <= time() + self::REFRESH_MARGIN) {
            $this->refresh();
        }

        $response = $this->client->apiRequest($method, $path, $body);

        if ($response->getStatus() === 401 && $this->refresh()) {
            $response = $this->client->apiRequest($method, $path, $body);
        }

        if ($response->getStatus() >= 400) {
            // never tokens or personal data in the log
            $this->log(sprintf(
                '%s %s -> HTTP %d %s',
                strtoupper($method),
                (string) strtok($path, '?'),
                $response->getStatus(),
                $this->errorParser->message($response)
            ));
        }

        return $response;
    }

    /**
     * Whether the shop holds a usable connection
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connection->isConnected();
    }

    /**
     * Whether the setup wizard was finished and the connection is still in place
     *
     * @return bool
     */
    public function isSetupComplete(): bool
    {
        return $this->connection->isSetupComplete() && $this->isConnected();
    }

    /**
     * Check the connection with one GET /v1/integration/status per request
     *
     * Only a refused token refresh drops the connection. A 5xx or a network error must not disconnect the shop.
     *
     * @return string live | revoked | unreachable | disconnected
     */
    public function probeConnection(): string
    {
        if ($this->probe !== null) {
            return $this->probe;
        }
        if (!$this->isConnected()) {
            return $this->probe = self::PROBE_DISCONNECTED;
        }

        $this->statusBody = [];
        try {
            $response = $this->apiRequest('GET', '/v1/integration/status');
        } catch (OvebotException $e) {
            return $this->probe = $this->isConnected() ? self::PROBE_UNREACHABLE : self::PROBE_REVOKED;
        }

        if ($response->isSuccess()) {
            $this->statusBody = $response->getBody();

            return $this->probe = self::PROBE_LIVE;
        }

        // a 401 whose refresh was refused has already dropped the tokens
        return $this->probe = $this->isConnected() ? self::PROBE_UNREACHABLE : self::PROBE_REVOKED;
    }

    /**
     * The "integration" object of the status: products, order_info, counts; empty when it could not be read
     *
     * @return array
     */
    public function getIntegration(): array
    {
        $this->probeConnection();

        return isset($this->statusBody['integration']) && is_array($this->statusBody['integration'])
            ? $this->statusBody['integration']
            : [];
    }

    /**
     * Number of products indexed by the AI agent; 0 when unknown
     *
     * @return int
     */
    public function getIndexedProductCount(): int
    {
        $integration = $this->getIntegration();

        return isset($integration['counts']['products']) ? (int) $integration['counts']['products'] : 0;
    }

    /**
     * Align the two mirrored switches with the account, which is the source of truth
     *
     * @return void
     */
    public function syncSettings()
    {
        if ($this->probeConnection() !== self::PROBE_LIVE) {
            return;
        }

        $integration = $this->getIntegration();
        $changed = false;

        if (array_key_exists('order_info', $integration)
            && !empty($integration['order_info']) !== $this->connection->isOrderEnabled()
        ) {
            $this->connection->setOrderEnabled(!empty($integration['order_info']));
            $changed = true;
        }
        if (array_key_exists('products', $integration)
            && !empty($integration['products']) !== $this->connection->isProductsRecommend()
        ) {
            $this->connection->setProductsRecommend(!empty($integration['products']));
            $changed = true;
        }

        if ($changed) {
            $this->persist();
        }
    }

    /**
     * Send the endpoint URLs again when they are no longer the ones Ovebot.ai accepted last. Best effort.
     *
     * A change of the domain, of the protocol or of the URL settings of the shop changes the feed URL and the
     * order URL that the account knows.
     *
     * @return void
     */
    public function selfHealEndpoints()
    {
        if (!$this->isSetupComplete() || $this->probeConnection() !== self::PROBE_LIVE) {
            return;
        }

        $payload = $this->payloads->build($this->connection, $this->storeView);
        $last = $this->connection->getLastPush();

        if ($this->pushedUrls($payload) === [
            'feed_url' => isset($last['feed_url']) && is_scalar($last['feed_url']) ? (string) $last['feed_url'] : '',
            'api_url' => isset($last['api_url']) && is_scalar($last['api_url']) ? (string) $last['api_url'] : '',
        ]) {
            return;
        }

        $this->resyncSetup();
    }

    /**
     * Send the local configuration to /setup: widget language, order endpoint, product feed. Never throws.
     *
     * @param array $overrides see SetupPayloadBuilder::build()
     * @return array ['success' => bool, 'error' => string, 'payload' => array]
     */
    public function resyncSetup(array $overrides = []): array
    {
        $payload = $this->payloads->build($this->connection, $this->storeView, $overrides);

        if (!$this->isConnected()) {
            return [
                'success' => false,
                'error' => (string) __('Connect your store to Ovebot.ai first.'),
                'payload' => $payload,
            ];
        }

        try {
            $response = $this->apiRequest('PUT', $this->getSetupApiPath(), $payload);
        } catch (OvebotException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'payload' => $payload];
        }

        if (!$response->isSuccess()) {
            return ['success' => false, 'error' => $this->errorParser->message($response), 'payload' => $payload];
        }

        // a failed save is only logged: the account has the URLs, and the next page load sends them again
        $this->connection->setLastPush($this->pushedUrls($payload));
        $this->persist();

        return ['success' => true, 'error' => '', 'payload' => $payload];
    }

    /**
     * "Finish setup" of the wizard
     *
     * Keeps the product source, sends the setup with the product recommendations and the order tracking
     * switched on, and only then marks the setup as finished and switches the chat on.
     *
     * @param bool $productsBuiltin whether the built-in feed is the product source
     * @return array ['success' => bool, 'error' => string]
     */
    public function finish(bool $productsBuiltin): array
    {
        if (!$this->isConnected()) {
            return ['success' => false, 'error' => (string) __('Connect your store to Ovebot.ai first.')];
        }

        // stored before the call, so it is kept when the call fails and when the tokens are read again
        $this->connection->setProductsBuiltin($productsBuiltin);
        if (!$this->persist()) {
            return ['success' => false, 'error' => (string) __('The Ovebot.ai connection could not be saved.')];
        }

        // The wizard has no switches for these two: going live turns them on. The stored values are not used,
        // since syncSettings() copies them from the account on every page load of the wizard, and an agent that
        // has them off in the account would otherwise stay off.
        $resync = $this->resyncSetup(['products_recommend' => true, 'order_enabled' => true]);
        if (!$resync['success']) {
            return ['success' => false, 'error' => $resync['error']];
        }

        $payload = $resync['payload'];
        $this->connection->setSetupComplete(true);
        $this->connection->setChatEnabled(true);
        // the account now has what was sent: the two mirrored switches follow it
        $this->connection->setOrderEnabled(!empty($payload['order_info']['enabled']));
        $this->connection->setProductsRecommend(!empty($payload['products']['enabled']));

        if (!$this->persist()) {
            return ['success' => false, 'error' => (string) __('The Ovebot.ai connection could not be saved.')];
        }

        return ['success' => true, 'error' => ''];
    }

    /**
     * "Save settings" of the settings page
     *
     * The chat switch and the appearance stay in the shop and are stored first. The three switches that the
     * account mirrors are sent to /setup and stored only once Ovebot.ai accepted them, so a refused write
     * cannot leave the shop and the account in disagreement.
     *
     * @param bool $chatEnabled
     * @param array $widget already cleaned by Widget\Settings::sanitize()
     * @param bool $productsBuiltin
     * @param bool $productsRecommend
     * @param bool $orderEnabled
     * @param bool|null $addToCart null keeps the stored value
     * @return array ['status' => one of the SAVE_* constants, 'error' => string, 'effective' => array]
     */
    public function saveSettings(
        bool $chatEnabled,
        array $widget,
        bool $productsBuiltin,
        bool $productsRecommend,
        bool $orderEnabled,
        ?bool $addToCart = null
    ): array {
        $switches = [
            'products_builtin' => $productsBuiltin,
            'products_recommend' => $productsRecommend,
            'order_enabled' => $orderEnabled,
            // null: the "Add to cart" switch keeps its stored value
            'add_to_cart' => $addToCart === null ? $this->connection->isAddToCart() : $addToCart,
        ];

        $this->connection->setChatEnabled($chatEnabled);
        $this->connection->setWidget($widget);

        if (!$this->isConnected()) {
            // nothing to agree with: the switches are kept as chosen and go out at the next connection
            $this->applySwitches($switches);

            return $this->saveResult($this->persist() ? self::SAVE_NEEDS_RECONNECT : self::SAVE_FAILED);
        }

        // stored before the call: the setup takes the widget language from the connection, and the call may
        // read the connection again from the database
        if (!$this->persist()) {
            return $this->saveResult(self::SAVE_FAILED);
        }

        $resync = $this->resyncSetup($switches);
        if (!$resync['success']) {
            return $this->saveResult(self::SAVE_SYNC_FAILED, $resync['error']);
        }

        $this->applySwitches($switches);

        return $this->saveResult($this->persist() ? self::SAVE_OK : self::SAVE_FAILED);
    }

    /**
     * The stored values of the switches that the account mirrors
     *
     * @return array ['products_builtin' => bool, 'products_recommend' => bool, 'order_enabled' => bool,
     *               'add_to_cart' => bool]
     */
    public function effectiveSwitches(): array
    {
        return [
            'products_builtin' => $this->connection->isProductsBuiltin(),
            'products_recommend' => $this->connection->isProductsRecommend(),
            'order_enabled' => $this->connection->isOrderEnabled(),
            'add_to_cart' => $this->connection->isAddToCart(),
        ];
    }

    /**
     * Replace the hash of the feed URL
     *
     * While Ovebot.ai reads the built-in feed, the new URL is sent first and the hash is stored only once it
     * was accepted: a refused write leaves the old URL, which still works. Otherwise the change stays in the
     * shop.
     *
     * @return array ['success' => bool, 'error' => string, 'hash' => string, 'url' => string, 'synced' => bool]
     */
    public function regenerateFeedHash(): array
    {
        $failure = ['success' => false, 'error' => '', 'hash' => '', 'url' => '', 'synced' => false];

        try {
            $hash = $this->random->hex(16);
        } catch (\Exception $e) {
            $this->log('Feed hash could not be generated: ' . $e->getMessage());

            return array_merge($failure, ['error' => (string) __('A new value could not be generated.')]);
        }

        $synced = false;
        if ($this->isConnected()
            && $this->connection->isProductsRecommend()
            && $this->connection->isProductsBuiltin()
        ) {
            $resync = $this->resyncSetup(['feed_hash' => $hash]);
            if (!$resync['success']) {
                return array_merge($failure, ['error' => $resync['error']]);
            }
            $synced = true;
        }

        $this->connection->setFeedHash($hash);
        if (!$this->persist()) {
            return array_merge($failure, ['error' => (string) __('The Ovebot.ai connection could not be saved.')]);
        }

        return [
            'success' => true,
            'error' => '',
            'hash' => $hash,
            'url' => $this->storeView->getFeedUrl($hash),
            'synced' => $synced,
        ];
    }

    /**
     * Replace the user and the password of the order endpoint
     *
     * Same order as for the feed hash: sent first, stored once accepted. Then the failed authentications are
     * forgotten, so the servers of Ovebot.ai do not stay blocked because of calls made with the old credentials.
     *
     * @return array ['success' => bool, 'error' => string, 'user' => string, 'pass' => string, 'synced' => bool]
     */
    public function regenerateOrderCreds(): array
    {
        $failure = ['success' => false, 'error' => '', 'user' => '', 'pass' => '', 'synced' => false];

        try {
            $user = $this->storeView->getDomainSlug() . '_' . $this->random->hex(4);
            $pass = $this->random->hex(16);
        } catch (\Exception $e) {
            $this->log('Credentials could not be generated: ' . $e->getMessage());

            return array_merge($failure, ['error' => (string) __('A new value could not be generated.')]);
        }

        $synced = false;
        if ($this->isConnected()) {
            $resync = $this->resyncSetup(['order_user' => $user, 'order_pass' => $pass]);
            if (!$resync['success']) {
                return array_merge($failure, ['error' => $resync['error']]);
            }
            $synced = true;
        }

        $this->connection->setOrderCredentials($user, $pass);
        if (!$this->persist()) {
            return array_merge($failure, ['error' => (string) __('The Ovebot.ai connection could not be saved.')]);
        }

        try {
            $this->rateLimits->clearAll();
        } catch (\Exception $e) {
            // the credentials are in place, and a block that stays ends by itself
            $this->log('Rate limit table not cleared (' . get_class($e) . ').');
        }

        return ['success' => true, 'error' => '', 'user' => $user, 'pass' => $pass, 'synced' => $synced];
    }

    /**
     * Generate the feed hash and the order endpoint credentials when they are missing; never replaces them
     *
     * @return void
     */
    public function ensureCredentials()
    {
        $changed = false;

        try {
            if ($this->connection->getFeedHash() === '') {
                $this->connection->setFeedHash($this->random->hex(16));
                $changed = true;
            }

            $user = $this->connection->getOrderUser();
            $pass = $this->connection->getOrderPass();
            if ($user === '' || $pass === '') {
                $this->connection->setOrderCredentials(
                    $user !== '' ? $user : $this->storeView->getDomainSlug() . '_' . $this->random->hex(4),
                    $pass !== '' ? $pass : $this->random->hex(16)
                );
                $changed = true;
            }
        } catch (\Exception $e) {
            $this->log('Credentials could not be generated: ' . $e->getMessage());

            return;
        }

        if ($changed) {
            $this->persist();
        }
    }

    /**
     * Workspace slug; empty when missing
     *
     * @return string
     */
    public function getWorkspace(): string
    {
        return $this->connection->getWorkspace();
    }

    /**
     * Agent public id; empty for the default agent
     *
     * @return string
     */
    public function getAgent(): string
    {
        $agent = $this->connection->getAgent();

        return $agent === 'default' ? '' : $agent;
    }

    /**
     * Agent segment of the API paths: "default" for the default agent
     *
     * @return string
     */
    public function getAgentForApi(): string
    {
        $agent = $this->getAgent();

        return $agent !== '' ? $agent : 'default';
    }

    /**
     * Text of the connection badge: "{workspace}:{agent|default}"
     *
     * @return string
     */
    public function getConnectionLabel(): string
    {
        $workspace = $this->getWorkspace();

        return $workspace !== '' ? $workspace . ':' . $this->getAgentForApi() : '';
    }

    /**
     * URL in the merchant's Ovebot.ai account; empty when there is no workspace
     *
     * @param string $path for example '/products'
     * @return string
     */
    public function getAccountUrl(string $path = ''): string
    {
        $workspace = $this->getWorkspace();

        return $workspace !== '' ? 'https://' . $workspace . '.ovebot.ai' . $path : '';
    }

    /**
     * URL of the agent settings in the account; the pages of the account carry no agent, whatever the agent
     *
     * @return string
     */
    public function getAgentSetupUrl(): string
    {
        return $this->getAccountUrl('/setup');
    }

    /**
     * URL of "Start Free": the register page with the plan and the shop domain filled in
     *
     * @return string
     */
    public function getRegisterUrl(): string
    {
        return $this->client->buildRegisterUrl($this->storeView->getDomain());
    }

    /**
     * API path of the agent, the base of /setup and /knowledge-base
     *
     * @return string
     */
    public function getAgentApiPath(): string
    {
        return '/v1/workspaces/' . rawurlencode($this->getWorkspace())
            . '/agents/' . rawurlencode($this->getAgentForApi());
    }

    /**
     * API path of the setup of the agent
     *
     * @return string
     */
    public function getSetupApiPath(): string
    {
        return $this->getAgentApiPath() . '/setup';
    }

    /**
     * API path of the knowledge base of the agent
     *
     * @return string
     */
    public function getKbApiPath(): string
    {
        return $this->getAgentApiPath() . '/knowledge-base';
    }

    /**
     * URL of a knowledge base entry in the account; empty when there is no workspace
     *
     * @param int $entryId
     * @return string
     */
    public function getKbEditUrl(int $entryId): string
    {
        return $this->getAccountUrl('/knowledge-base/' . $entryId . '/edit');
    }

    /**
     * Ids of the CMS pages chosen in the wizard
     *
     * @return int[]
     */
    public function getKbPageIds(): array
    {
        return $this->connection->getKbPageIds();
    }

    /**
     * Keep the page selection of the wizard, so a second run shows the same boxes ticked
     *
     * @param array $pageIds
     * @return bool
     */
    public function saveKbPageIds(array $pageIds): bool
    {
        $this->connection->setKbPageIds($pageIds);

        return $this->persist();
    }

    /**
     * Knowledge base entries of the agent, for the dashboard. Never throws.
     *
     * @return array ['entries' => [['id', 'title', 'is_active', 'edit_url'], ...], 'error' => bool]
     */
    public function getKbEntries(): array
    {
        if (!$this->isConnected()) {
            return ['entries' => [], 'error' => false];
        }

        $entries = [];
        $page = 1;
        $fetched = 0;

        do {
            try {
                $response = $this->apiRequest(
                    'GET',
                    $this->getKbApiPath() . '?' . http_build_query(['page' => $page, 'per_page' => self::KB_PAGE_SIZE])
                );
            } catch (OvebotException $e) {
                return ['entries' => [], 'error' => true];
            }
            if (!$response->isSuccess()) {
                return ['entries' => [], 'error' => true];
            }

            $body = $response->getBody();
            $raw = isset($body['entries']) && is_array($body['entries']) ? $body['entries'] : [];
            foreach ($raw as $entry) {
                if (empty($entry['id'])) {
                    continue;
                }
                $entries[] = [
                    'id' => (int) $entry['id'],
                    'title' => isset($entry['title']) && is_scalar($entry['title']) ? (string) $entry['title'] : '',
                    'is_active' => !empty($entry['is_active']),
                    'edit_url' => $this->getKbEditUrl((int) $entry['id']),
                ];
            }

            $total = isset($body['total']) ? (int) $body['total'] : 0;
            $fetched += count($raw);
            $page++;
        } while ($raw && $fetched < $total && $page <= self::KB_MAX_PAGES);

        return ['entries' => $entries, 'error' => false];
    }

    /**
     * Shorten the timeout of the calls that follow; used where a slow answer would hold up the merchant
     *
     * @param int $seconds
     * @return void
     */
    public function setTimeout(int $seconds)
    {
        $this->client->setTimeout($seconds);
    }

    /**
     * Close the connection: revoke it at Ovebot.ai (best effort), then drop the tokens, the workspace and the keys
     *
     * The keys are the hash of the feed URL and the credentials of the order endpoint. The account that is left
     * was given them; they stop working here, so a closed connection can read neither the feed nor the orders any
     * more. The module page makes new ones when it is opened next (ensureCredentials()), and the next connection
     * receives them, because the URLs accepted last are forgotten as well (selfHealEndpoints()).
     *
     * The setup, the agent, the switches and the appearance stay, so connecting again to the same workspace and
     * agent leads straight to the dashboard. The workspace is remembered for that comparison.
     *
     * @return void
     */
    public function disconnect()
    {
        if ($this->connection->getAccessToken() !== '' || $this->connection->getRefreshToken() !== '') {
            try {
                $this->apiRequest('POST', '/v1/disconnect');
            } catch (\Exception $e) {
                // the local cleanup below must happen regardless
                $this->log('Remote disconnect failed: ' . $e->getMessage());
            }
        }

        $workspace = $this->connection->getWorkspace();
        if ($workspace !== '') {
            $this->connection->setLastWorkspace($workspace);
        }

        $this->connection->clearTokens();
        $this->connection->setWorkspace('');
        $this->connection->setFeedHash('');
        $this->connection->setOrderCredentials('', '');
        $this->connection->setLastPush([]);
        $this->persist();

        $this->client->setAccessToken('');
        $this->probe = null;
        $this->statusBody = null;
    }

    /**
     * Disconnect with a short timeout and without ever throwing; used at uninstall
     *
     * @param int $timeoutSeconds
     * @return void
     */
    public function disconnectQuietly(int $timeoutSeconds = 5)
    {
        $this->client->setTimeout($timeoutSeconds);

        try {
            $this->disconnect();
        } catch (\Throwable $e) {
            // the uninstall must never be blocked by the remote side
            $this->log('Quiet disconnect failed: ' . $e->getMessage());
        }
    }

    /**
     * Refresh the tokens
     *
     * The refresh token rotates, so the refresh is serialized with a lock, and the tokens are read again once
     * the lock is held: another process may have refreshed already.
     *
     * @return bool true when a usable access token is in place afterwards
     */
    private function refresh(): bool
    {
        $refreshToken = $this->connection->getRefreshToken();
        if ($refreshToken === '') {
            return false;
        }

        $locked = $this->lock(self::LOCK_NAME);

        try {
            if ($locked) {
                $fresh = $this->connections->reload();
                $freshToken = $fresh->getRefreshToken();
                if ($freshToken === '') {
                    $this->adopt($fresh);

                    return false;
                }
                if ($freshToken !== $refreshToken) {
                    // another process rotated the tokens while we waited: adopt them
                    $this->adopt($fresh);
                    $this->client->setAccessToken($fresh->getAccessToken());

                    return true;
                }
                $this->adopt($fresh);
            }

            try {
                $tokens = $this->client->refreshToken($refreshToken);
            } catch (AuthException $e) {
                // refused: drop the tokens only; workspace, agent and chat stay, so the widget keeps working
                $this->log('Token refresh rejected: ' . $e->getMessage());
                $this->expireTokens();

                return false;
            } catch (OvebotException $e) {
                // network error or 5xx: NOT a revocation
                $this->log('Token refresh failed: ' . $e->getMessage());

                return false;
            }

            return $this->storeTokens($tokens);
        } finally {
            if ($locked) {
                $this->unlock(self::LOCK_NAME);
            }
        }
    }

    /**
     * Store the body of the token endpoint
     *
     * @param array $tokens
     * @return bool
     */
    private function storeTokens(array $tokens): bool
    {
        $accessToken = isset($tokens['access_token']) && is_string($tokens['access_token'])
            ? $tokens['access_token']
            : '';
        $refreshToken = !empty($tokens['refresh_token']) && is_string($tokens['refresh_token'])
            ? $tokens['refresh_token']
            : $this->connection->getRefreshToken();
        $lifetime = isset($tokens['expires_in']) && (int) $tokens['expires_in'] > 0
            ? (int) $tokens['expires_in']
            : self::DEFAULT_TOKEN_LIFETIME;

        $this->connection->setTokens($accessToken, $refreshToken, time() + $lifetime);

        if (!empty($tokens['workspace']['slug']) && is_string($tokens['workspace']['slug'])
            && preg_match(Connection::WORKSPACE_PATTERN, $tokens['workspace']['slug'])
        ) {
            $this->connection->setWorkspace($tokens['workspace']['slug']);
        }

        // the token answer has null for the default agent; /v1/me gives the real value right after
        if (array_key_exists('agent', $tokens)) {
            $this->connection->setAgent($this->agentSlug($tokens['agent']));
        }

        $saved = $this->persist();
        $this->client->setAccessToken($accessToken);

        return $saved;
    }

    /**
     * Write the three mirrored switches on the connection
     *
     * @param array $switches products_builtin, products_recommend, order_enabled
     * @return void
     */
    private function applySwitches(array $switches)
    {
        $this->connection->setProductsBuiltin((bool) $switches['products_builtin']);
        $this->connection->setProductsRecommend((bool) $switches['products_recommend']);
        $this->connection->setOrderEnabled((bool) $switches['order_enabled']);
        if (array_key_exists('add_to_cart', $switches)) {
            $this->connection->setAddToCart((bool) $switches['add_to_cart']);
        }
    }

    /**
     * The answer of saveSettings()
     *
     * @param string $status one of the SAVE_* constants
     * @param string $error
     * @return array
     */
    private function saveResult(string $status, string $error = ''): array
    {
        if ($status === self::SAVE_FAILED && $error === '') {
            $error = (string) __('The Ovebot.ai connection could not be saved.');
        }

        return ['status' => $status, 'error' => $error, 'effective' => $this->effectiveSwitches()];
    }

    /**
     * The endpoint URLs of a setup payload; the feed URL is empty when the payload carries none
     *
     * @param array $payload
     * @return array ['feed_url' => string, 'api_url' => string]
     */
    private function pushedUrls(array $payload): array
    {
        return [
            'feed_url' => isset($payload['products']['feed_url']) ? (string) $payload['products']['feed_url'] : '',
            'api_url' => isset($payload['order_info']['api_url']) ? (string) $payload['order_info']['api_url'] : '',
        ];
    }

    /**
     * Drop the tokens after a refused refresh
     *
     * @return void
     */
    private function expireTokens()
    {
        $this->connection->clearTokens();
        $this->persist();
        $this->client->setAccessToken('');
    }

    /**
     * After connecting, read the agent of the token and start the wizard again for another agent or workspace
     *
     * The agent comes from GET /v1/me, best effort; the workspace came with the tokens.
     *
     * @param string $previousAgent
     * @param string $previousWorkspace empty when the shop was never connected
     * @return void
     */
    private function syncAgentFromMe(string $previousAgent, string $previousWorkspace)
    {
        $agent = $this->agentFromMe();
        if ($agent !== null) {
            $this->connection->setAgent($agent);
        }

        $workspace = $this->connection->getWorkspace();
        $agentChanged = $agent !== null && $agent !== $previousAgent;
        // the default agent of another account has the same (empty) id, so the agent alone does not tell them apart
        $workspaceChanged = $previousWorkspace !== '' && $workspace !== '' && $workspace !== $previousWorkspace;

        // Another agent or another account than the one the wizard was finished for: the synced pages and the
        // endpoints belong to the OLD one. Run the wizard again, clear the page selection and let the switches
        // sync from the new agent.
        if ($agentChanged || $workspaceChanged) {
            $this->connection->setSetupComplete(false);
            $this->connection->setKbPageIds([]);
            $this->connection->setProductsRecommend(null);
            $this->connection->setOrderEnabled(null);
        }

        if ($agent !== null || $workspaceChanged) {
            $this->persist();
        }
    }

    /**
     * The agent named by GET /v1/me
     *
     * @return string|null empty for the default agent; null when the answer has nothing to use
     */
    private function agentFromMe(): ?string
    {
        try {
            $response = $this->apiRequest('GET', '/v1/me');
        } catch (OvebotException $e) {
            return null;
        }

        $body = $response->getBody();
        // an empty agent is the default agent; a MISSING key means there is nothing to use
        if (!$response->isSuccess() || !array_key_exists('agent', $body)) {
            return null;
        }

        return $this->agentSlug($body['agent']);
    }

    /**
     * Read the agent: null (default agent), a string id, or {public_id}
     *
     * @param mixed $agent
     * @return string empty for the default agent
     */
    private function agentSlug($agent): string
    {
        if (is_array($agent)) {
            return isset($agent['public_id']) && is_scalar($agent['public_id']) ? (string) $agent['public_id'] : '';
        }

        $slug = is_scalar($agent) ? (string) $agent : '';

        return $slug === 'default' ? '' : $slug;
    }

    /**
     * Save the connection; a failure is logged, not raised
     *
     * When the save changes what the storefront widget shows (chat switch, setup, workspace, agent, appearance),
     * the cached pages that carry the widget are removed, so the change is seen at once.
     *
     * @return bool
     */
    private function persist(): bool
    {
        try {
            $this->connections->save($this->connection);
        } catch (CouldNotSaveException $e) {
            // a database message may quote stored values, so only the kind of error is logged
            $previous = $e->getPrevious();
            $this->log('Connection not saved (' . get_class($previous ?: $e) . ').');

            return false;
        }

        $state = $this->widgetOptions->getState($this->connection);
        if ($state !== $this->widgetState) {
            $this->widgetState = $state;
            $this->widgetCache->clean();
        }

        return true;
    }

    /**
     * Continue with the connection as it was read again from the database
     *
     * Another request saved it, and cleaned the cached pages if it changed the widget.
     *
     * @param Connection $fresh
     * @return void
     */
    private function adopt(Connection $fresh)
    {
        $this->connection = $fresh;
        $this->widgetState = $this->widgetOptions->getState($fresh);
    }

    /**
     * Take the refresh lock; without it the refresh still runs, just not serialized
     *
     * @param string $name
     * @return bool
     */
    private function lock(string $name): bool
    {
        try {
            return $this->lockManager->lock($name, self::LOCK_TIMEOUT);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Release the refresh lock
     *
     * @param string $name
     * @return void
     */
    private function unlock(string $name)
    {
        try {
            $this->lockManager->unlock($name);
        } catch (\Exception $e) {
            // the lock ends with the database connection anyway
            $this->log('Lock not released: ' . $e->getMessage());
        }
    }

    /**
     * Write a warning in the module log. Callers never pass tokens, passwords or personal data.
     *
     * @param string $message
     * @return void
     */
    private function log(string $message)
    {
        $this->logger->warning($message);
    }
}
