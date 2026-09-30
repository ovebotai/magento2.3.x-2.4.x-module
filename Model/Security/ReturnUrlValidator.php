<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Security;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Checks the admin URL the OAuth callback redirects to.
 *
 * The URL is one the module stored itself when the authorization started. It still has to be http(s) and on a
 * host that belongs to this Magento installation, so a changed database row cannot send the merchant elsewhere.
 */
class ReturnUrlValidator
{
    private const XML_PATH_USE_CUSTOM_ADMIN_URL = 'admin/url/use_custom';
    private const XML_PATH_CUSTOM_ADMIN_URL = 'admin/url/custom';

    private const URL_PATTERN = '#^https?://(?:[^/?\#@]*@)?(\[[^\]]+\]|[^/?\#:]+)#i';

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(StoreManagerInterface $storeManager, ScopeConfigInterface $scopeConfig)
    {
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Whether the URL may be used as a redirect target
     *
     * @param string $url
     * @return bool
     */
    public function isAllowed(string $url): bool
    {
        $host = $this->host($url);

        return $host !== '' && in_array($host, $this->knownHosts(), true);
    }

    /**
     * Hosts of this installation: every store, the admin store included, and the custom admin URL
     *
     * @return string[]
     */
    private function knownHosts(): array
    {
        $hosts = [];

        foreach ($this->storeManager->getStores(true) as $store) {
            $hosts[] = $this->host((string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, false));
            $hosts[] = $this->host((string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true));
        }

        if ($this->scopeConfig->isSetFlag(self::XML_PATH_USE_CUSTOM_ADMIN_URL)) {
            $hosts[] = $this->host((string) $this->scopeConfig->getValue(self::XML_PATH_CUSTOM_ADMIN_URL));
        }

        return array_values(array_unique(array_filter($hosts, 'strlen')));
    }

    /**
     * Host of an http(s) URL, lower case; empty when the URL is something else
     *
     * @param string $url
     * @return string
     */
    private function host(string $url): string
    {
        // no control characters, spaces or backslashes: browsers read them in ways a pattern does not
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) || !preg_match(self::URL_PATTERN, $url, $match)) {
            return '';
        }

        return strtolower($match[1]);
    }
}
