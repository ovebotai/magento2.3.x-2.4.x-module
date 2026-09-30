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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Advanced options from Stores > Configuration. The shop has one connection, so they are read at the default
 * level: a value set for a website or a store view only is not used.
 */
class Config
{
    public const XML_PATH_MAX_AGE_DAYS = 'ovebot_chat/orders/max_age_days';
    public const XML_PATH_TRACKING_FINDERS = 'ovebot_chat/tracking/finders';
    public const XML_PATH_TRACKING_URLS = 'ovebot_chat/tracking/urls';
    public const XML_PATH_ACCOUNT_HOST = 'ovebot_chat/developer/account_host';
    public const XML_PATH_API_HOST = 'ovebot_chat/developer/api_host';

    public const DEFAULT_MAX_AGE_DAYS = 60;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Json
     */
    private $json;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $json
     */
    public function __construct(ScopeConfigInterface $scopeConfig, Json $json)
    {
        $this->scopeConfig = $scopeConfig;
        $this->json = $json;
    }

    /**
     * Orders older than this many days are not returned to the AI agent
     *
     * @return int
     */
    public function getMaxOrderAgeDays(): int
    {
        $days = (int) $this->value(self::XML_PATH_MAX_AGE_DAYS);

        return $days > 0 ? $days : self::DEFAULT_MAX_AGE_DAYS;
    }

    /**
     * Codes of the tracking sources to use; empty means all of them
     *
     * @return string[]
     */
    public function getTrackingFinderCodes(): array
    {
        $codes = array_map('trim', explode(',', $this->value(self::XML_PATH_TRACKING_FINDERS)));

        return array_values(array_unique(array_filter($codes, 'strlen')));
    }

    /**
     * Tracking URL templates set by the merchant: carrier code => URL with {code}
     *
     * @return string[]
     */
    public function getTrackingUrlTemplates(): array
    {
        $raw = $this->value(self::XML_PATH_TRACKING_URLS);
        if ($raw === '') {
            return [];
        }

        try {
            $rows = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        $templates = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $code = isset($row['code']) && is_scalar($row['code']) ? strtolower(trim((string) $row['code'])) : '';
            $url = isset($row['url']) && is_scalar($row['url']) ? trim((string) $row['url']) : '';
            if ($code !== '' && $url !== '') {
                $templates[$code] = $url;
            }
        }

        return $templates;
    }

    /**
     * Account host override; empty means the default host
     *
     * @return string
     */
    public function getAccountHost(): string
    {
        return trim($this->value(self::XML_PATH_ACCOUNT_HOST));
    }

    /**
     * API host override; empty means the default host
     *
     * @return string
     */
    public function getApiHost(): string
    {
        return trim($this->value(self::XML_PATH_API_HOST));
    }

    /**
     * Read a value of the default level
     *
     * @param string $path
     * @return string
     */
    private function value(string $path): string
    {
        $value = $this->scopeConfig->getValue($path);

        return is_scalar($value) ? (string) $value : '';
    }
}
