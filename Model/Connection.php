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

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Ovebot\Chat\Model\ResourceModel\Connection as ConnectionResource;

/**
 * The connection of the shop to Ovebot.ai: tokens, agent, switches, widget settings, endpoint keys.
 *
 * Tokens and the order API password are kept encrypted in the model data, so they are never written in clear.
 *
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class Connection extends AbstractModel
{
    // \z, not $: in PHP "$" also matches before a trailing line break
    public const WORKSPACE_PATTERN = '/^[a-z0-9-]+\z/';

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var Json
     */
    private $json;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param EncryptorInterface $encryptor
     * @param Json $json
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        EncryptorInterface $encryptor,
        Json $json,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->encryptor = $encryptor;
        $this->json = $json;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    /**
     * Define the resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ConnectionResource::class);
    }

    /**
     * Access token, decrypted
     *
     * @return string
     */
    public function getAccessToken(): string
    {
        return $this->getSecret('access_token');
    }

    /**
     * Refresh token, decrypted
     *
     * @return string
     */
    public function getRefreshToken(): string
    {
        return $this->getSecret('refresh_token');
    }

    /**
     * Unix time when the access token expires
     *
     * @return int
     */
    public function getTokenExpires(): int
    {
        return (int) $this->getData('token_expires');
    }

    /**
     * Store a token pair
     *
     * @param string $accessToken
     * @param string $refreshToken
     * @param int $expires unix time
     * @return $this
     */
    public function setTokens(string $accessToken, string $refreshToken, int $expires)
    {
        $this->setSecret('access_token', $accessToken);
        $this->setSecret('refresh_token', $refreshToken);

        return $this->setData('token_expires', max(0, $expires));
    }

    /**
     * Drop the tokens; workspace, agent and the switches stay, so the storefront widget keeps working
     *
     * @return $this
     */
    public function clearTokens()
    {
        return $this->setTokens('', '', 0);
    }

    /**
     * Workspace slug; empty when it is missing or not a valid slug
     *
     * @return string
     */
    public function getWorkspace(): string
    {
        $workspace = (string) $this->getData('workspace');

        return preg_match(self::WORKSPACE_PATTERN, $workspace) ? $workspace : '';
    }

    /**
     * Set the workspace slug; an invalid slug is stored as empty (it ends up in a script host name)
     *
     * @param string $workspace
     * @return $this
     */
    public function setWorkspace(string $workspace)
    {
        return $this->setData('workspace', preg_match(self::WORKSPACE_PATTERN, $workspace) ? $workspace : null);
    }

    /**
     * Workspace of the connection that was closed last; empty when none was closed or the slug is not valid
     *
     * @return string
     */
    public function getLastWorkspace(): string
    {
        $workspace = (string) $this->getData('last_workspace');

        return preg_match(self::WORKSPACE_PATTERN, $workspace) ? $workspace : '';
    }

    /**
     * Remember the workspace of the connection that is being closed
     *
     * @param string $workspace
     * @return $this
     */
    public function setLastWorkspace(string $workspace)
    {
        return $this->setData('last_workspace', preg_match(self::WORKSPACE_PATTERN, $workspace) ? $workspace : null);
    }

    /**
     * Agent public id; empty for the default agent
     *
     * @return string
     */
    public function getAgent(): string
    {
        return (string) $this->getData('agent');
    }

    /**
     * Set the agent public id; empty for the default agent
     *
     * @param string $agent
     * @return $this
     */
    public function setAgent(string $agent)
    {
        return $this->setData('agent', $agent !== '' ? $agent : null);
    }

    /**
     * Whether the shop holds a usable connection
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->getRefreshToken() !== '' && $this->getWorkspace() !== '';
    }

    /**
     * Whether the setup wizard was finished
     *
     * @return bool
     */
    public function isSetupComplete(): bool
    {
        return (bool) $this->getData('setup_complete');
    }

    /**
     * Mark the setup wizard as finished or not
     *
     * @param bool $complete
     * @return $this
     */
    public function setSetupComplete(bool $complete)
    {
        return $this->setData('setup_complete', $complete ? 1 : 0);
    }

    /**
     * Whether the chat widget is switched on
     *
     * @return bool
     */
    public function isChatEnabled(): bool
    {
        return (bool) $this->getData('chat_status');
    }

    /**
     * Switch the chat widget on or off
     *
     * @param bool $enabled
     * @return $this
     */
    public function setChatEnabled(bool $enabled)
    {
        return $this->setData('chat_status', $enabled ? 1 : 0);
    }

    /**
     * Whether the built-in feed is the product source; not set means on
     *
     * @return bool
     */
    public function isProductsBuiltin(): bool
    {
        return $this->getSwitch('products_builtin');
    }

    /**
     * Set the product source switch; null means not set
     *
     * @param bool|null $enabled
     * @return $this
     */
    public function setProductsBuiltin(?bool $enabled)
    {
        return $this->setSwitch('products_builtin', $enabled);
    }

    /**
     * Whether the agent recommends products; not set means on
     *
     * @return bool
     */
    public function isProductsRecommend(): bool
    {
        return $this->getSwitch('products_recommend');
    }

    /**
     * Set the product recommendations switch; null means not set
     *
     * @param bool|null $enabled
     * @return $this
     */
    public function setProductsRecommend(?bool $enabled)
    {
        return $this->setSwitch('products_recommend', $enabled);
    }

    /**
     * Whether the chat offers an "Add to cart" button and sees the cart; not set means on
     *
     * The shop is the only source: the account mirrors the value through the setup, and never sends it back.
     *
     * @return bool
     */
    public function isAddToCart(): bool
    {
        return $this->getSwitch('add_to_cart');
    }

    /**
     * Set the "Add to cart" switch; null means not set
     *
     * @param bool|null $enabled
     * @return $this
     */
    public function setAddToCart(?bool $enabled)
    {
        return $this->setSwitch('add_to_cart', $enabled);
    }

    /**
     * Whether order tracking is on; not set means on
     *
     * @return bool
     */
    public function isOrderEnabled(): bool
    {
        return $this->getSwitch('order_enabled');
    }

    /**
     * Set the order tracking switch; null means not set
     *
     * @param bool|null $enabled
     * @return $this
     */
    public function setOrderEnabled(?bool $enabled)
    {
        return $this->setSwitch('order_enabled', $enabled);
    }

    /**
     * Widget appearance settings
     *
     * @return array
     */
    public function getWidget(): array
    {
        return $this->getJson('widget');
    }

    /**
     * Set the widget appearance settings
     *
     * @param array $widget
     * @return $this
     */
    public function setWidget(array $widget)
    {
        return $this->setJson('widget', $widget);
    }

    /**
     * Ids of the CMS pages selected for the knowledge base
     *
     * @return int[]
     */
    public function getKbPageIds(): array
    {
        return $this->cleanIds($this->getJson('kb_page_ids'));
    }

    /**
     * Set the CMS pages selected for the knowledge base
     *
     * @param array $pageIds
     * @return $this
     */
    public function setKbPageIds(array $pageIds)
    {
        return $this->setJson('kb_page_ids', $this->cleanIds($pageIds));
    }

    /**
     * Hash that opens the product feed
     *
     * @return string
     */
    public function getFeedHash(): string
    {
        return (string) $this->getData('feed_hash');
    }

    /**
     * Set the feed hash
     *
     * @param string $hash
     * @return $this
     */
    public function setFeedHash(string $hash)
    {
        return $this->setData('feed_hash', $hash !== '' ? $hash : null);
    }

    /**
     * User of the order endpoint
     *
     * @return string
     */
    public function getOrderUser(): string
    {
        return (string) $this->getData('order_user');
    }

    /**
     * Order endpoint password, decrypted
     *
     * @return string
     */
    public function getOrderPass(): string
    {
        return $this->getSecret('order_pass');
    }

    /**
     * Set the order endpoint credentials
     *
     * @param string $user
     * @param string $password
     * @return $this
     */
    public function setOrderCredentials(string $user, string $password)
    {
        $this->setData('order_user', $user !== '' ? $user : null);

        return $this->setSecret('order_pass', $password);
    }

    /**
     * Endpoint URLs last accepted by Ovebot.ai: feed_url, api_url
     *
     * @return array
     */
    public function getLastPush(): array
    {
        return $this->getJson('last_push');
    }

    /**
     * Remember the endpoint URLs accepted by Ovebot.ai
     *
     * @param array $urls
     * @return $this
     */
    public function setLastPush(array $urls)
    {
        return $this->setJson('last_push', $urls);
    }

    /**
     * OAuth error waiting to be shown; empty when there is none or it expired
     *
     * @param int $now unix time
     * @return string
     */
    public function getOauthError(int $now): string
    {
        return (int) $this->getData('oauth_error_expires') > $now ? (string) $this->getData('oauth_error') : '';
    }

    /**
     * Keep an OAuth error to be shown once; an empty message clears it
     *
     * @param string $message
     * @param int $expires unix time
     * @return $this
     */
    public function setOauthError(string $message, int $expires)
    {
        $this->setData('oauth_error', $message !== '' ? $message : null);

        return $this->setData('oauth_error_expires', $message !== '' ? max(0, $expires) : 0);
    }

    /**
     * Preview token; empty when there is none or it expired
     *
     * @param int $now unix time
     * @return string
     */
    public function getPreviewToken(int $now): string
    {
        return (int) $this->getData('preview_expires') > $now ? (string) $this->getData('preview_token') : '';
    }

    /**
     * Set the preview token; an empty token clears it
     *
     * @param string $token
     * @param int $expires unix time
     * @return $this
     */
    public function setPreviewToken(string $token, int $expires)
    {
        $this->setData('preview_token', $token !== '' ? $token : null);

        return $this->setData('preview_expires', $token !== '' ? max(0, $expires) : 0);
    }

    /**
     * Read a switch where "not set" counts as on
     *
     * @param string $key
     * @return bool
     */
    private function getSwitch(string $key): bool
    {
        $value = $this->getData($key);

        return $value === null || $value === '' ? true : (bool) (int) $value;
    }

    /**
     * Write a switch; null keeps it "not set"
     *
     * @param string $key
     * @param bool|null $enabled
     * @return $this
     */
    private function setSwitch(string $key, ?bool $enabled)
    {
        return $this->setData($key, $enabled === null ? null : (int) $enabled);
    }

    /**
     * Read and decrypt a value
     *
     * @param string $key
     * @return string
     */
    private function getSecret(string $key): string
    {
        $value = (string) $this->getData($key);

        return $value !== '' ? (string) $this->encryptor->decrypt($value) : '';
    }

    /**
     * Encrypt and write a value
     *
     * @param string $key
     * @param string $value
     * @return $this
     */
    private function setSecret(string $key, string $value)
    {
        return $this->setData($key, $value !== '' ? $this->encryptor->encrypt($value) : null);
    }

    /**
     * Read a JSON column as array
     *
     * @param string $key
     * @return array
     */
    private function getJson(string $key): array
    {
        $value = (string) $this->getData($key);
        if ($value === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($value);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write an array into a JSON column
     *
     * @param string $key
     * @param array $value
     * @return $this
     */
    private function setJson(string $key, array $value)
    {
        return $this->setData($key, $value ? $this->json->serialize($value) : null);
    }

    /**
     * Keep the positive ids, once each
     *
     * @param array $ids
     * @return int[]
     */
    private function cleanIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }

        return array_values($clean);
    }
}
