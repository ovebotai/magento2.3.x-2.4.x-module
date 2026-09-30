<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\StoreContext;

use Magento\Framework\UrlFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;

/**
 * The default store view, as the storefront of the shop: its URLs, domain, currency and website.
 *
 * URLs are storefront URLs with the scope of this store view, also when they are built in the admin area.
 */
class StoreView
{
    public const ROUTE_FEED = 'ovebot/feed/index';
    public const ROUTE_ORDERS = 'ovebot/orders/index';
    public const ROUTE_OAUTH_CALLBACK = 'ovebot/oauth/callback';
    public const ROUTE_PREVIEW_VALIDATE = 'ovebot/preview/validate';

    /**
     * @var Store
     */
    private $store;

    /**
     * @var UrlFactory
     */
    private $urlFactory;

    /**
     * @var UrlInterface|null
     */
    private $url;

    /**
     * @param Store $store
     * @param UrlFactory $urlFactory
     */
    public function __construct(Store $store, UrlFactory $urlFactory)
    {
        $this->store = $store;
        $this->urlFactory = $urlFactory;
    }

    /**
     * The store view
     *
     * @return Store
     */
    public function getStore(): Store
    {
        return $this->store;
    }

    /**
     * Store view id
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return (int) $this->store->getId();
    }

    /**
     * Website of the store view: the products of the feed, their prices and their stock belong to it
     *
     * @return int
     */
    public function getWebsiteId(): int
    {
        return (int) $this->store->getWebsiteId();
    }

    /**
     * Code of the default display currency: the currency the customer sees
     *
     * @return string
     */
    public function getCurrencyCode(): string
    {
        return (string) $this->store->getDefaultCurrencyCode();
    }

    /**
     * Storefront base URL
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return (string) $this->store->getBaseUrl(UrlInterface::URL_TYPE_LINK);
    }

    /**
     * Host of the storefront, without the port: the OAuth site domain and the host of the callback URL
     *
     * @return string
     */
    public function getDomain(): string
    {
        $pattern = '#^[a-z][a-z0-9+.-]*://(?:[^/?\#@]*@)?(\[[^\]]+\]|[^/?\#:]+)#i';
        if (!preg_match($pattern, $this->getBaseUrl(), $match)) {
            return '';
        }

        return strtolower($match[1]);
    }

    /**
     * Domain as a slug, used in the generated user of the order endpoint: "www.my-shop.ro" gives "my_shop_ro"
     *
     * @return string
     */
    public function getDomainSlug(): string
    {
        $host = (string) preg_replace('/^www\./i', '', $this->getDomain());
        $slug = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $host)), '_');

        return $slug !== '' ? substr($slug, 0, 100) : 'shop';
    }

    /**
     * URL of the product feed
     *
     * @param string $hash
     * @return string
     */
    public function getFeedUrl(string $hash): string
    {
        return $this->getRouteUrl(self::ROUTE_FEED, ['hash' => $hash]);
    }

    /**
     * URL of the order lookup endpoint
     *
     * @return string
     */
    public function getOrdersUrl(): string
    {
        return $this->getRouteUrl(self::ROUTE_ORDERS);
    }

    /**
     * URL Ovebot.ai returns to after the authorization
     *
     * @return string
     */
    public function getOauthCallbackUrl(): string
    {
        return $this->getRouteUrl(self::ROUTE_OAUTH_CALLBACK);
    }

    /**
     * URL that validates a preview token
     *
     * @return string
     */
    public function getPreviewValidateUrl(): string
    {
        return $this->getRouteUrl(self::ROUTE_PREVIEW_VALIDATE);
    }

    /**
     * Public URL of a CMS page
     *
     * @param string $identifier
     * @return string
     */
    public function getCmsPageUrl(string $identifier): string
    {
        return $this->url()->getUrl(null, ['_direct' => $identifier, '_nosid' => true]);
    }

    /**
     * Storefront URL of a route, without a session id and without the ___store parameter
     *
     * @param string $route
     * @param array $query
     * @return string
     */
    public function getRouteUrl(string $route, array $query = []): string
    {
        $params = ['_nosid' => true];
        if ($query) {
            $params['_query'] = $query;
        }

        return $this->url()->getUrl($route, $params);
    }

    /**
     * URL builder with the scope of this store view
     *
     * @return UrlInterface
     */
    private function url(): UrlInterface
    {
        if ($this->url === null) {
            $this->url = $this->urlFactory->create();
            $this->url->setScope($this->store);
        }

        return $this->url;
    }
}
