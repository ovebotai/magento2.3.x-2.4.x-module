<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\ViewModel\Adminhtml;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\IntegrationFactory;

/**
 * Data of the dashboard: shortcuts, the state of the switches, products indexed by the agent, knowledge base.
 */
class Dashboard implements ArgumentInterface
{
    /**
     * Ids of the settings fields a link of the dashboard can point at
     */
    public const FIELD_CHAT = 'oveChatStatus';
    public const FIELD_PRODUCTS = 'oveProductsRecommend';
    public const FIELD_ORDERS = 'oveOrderEnabled';

    /**
     * @var IntegrationFactory
     */
    private $integrationFactory;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var array|null knowledge base of the agent, once read
     */
    private $knowledgeBase;

    /**
     * @param IntegrationFactory $integrationFactory
     * @param UrlInterface $url
     */
    public function __construct(IntegrationFactory $integrationFactory, UrlInterface $url)
    {
        $this->integrationFactory = $integrationFactory;
        $this->url = $url;
    }

    /**
     * URL of the merchant's Ovebot.ai account; empty when there is no workspace
     *
     * @return string
     */
    public function getAccountUrl(): string
    {
        return $this->integration()->getAccountUrl();
    }

    /**
     * URL of the agent settings in the account; empty when there is no workspace
     *
     * @return string
     */
    public function getAgentSetupUrl(): string
    {
        return $this->integration()->getAgentSetupUrl();
    }

    /**
     * URL of the settings view
     *
     * @param string $field id of the field to point at, one of the FIELD_* constants; empty for none
     * @return string
     */
    public function getSettingsUrl(string $field = ''): string
    {
        $params = ['view' => 'settings'];
        if ($field !== '') {
            $params['highlight'] = $field;
        }

        return $this->url->getUrl('ovebot_chat/dashboard/index', $params);
    }

    /**
     * Whether the chat widget is switched on
     *
     * @return bool
     */
    public function isChatEnabled(): bool
    {
        return $this->integration()->getConnection()->isChatEnabled();
    }

    /**
     * URL that opens the storefront with the chat open, where the merchant tries the agent
     *
     * @return string
     */
    public function getChatUrl(): string
    {
        return $this->url->getUrl('ovebot_chat/preview/open');
    }

    /**
     * Whether the agent recommends products
     *
     * @return bool
     */
    public function isProductsRecommend(): bool
    {
        return $this->integration()->getConnection()->isProductsRecommend();
    }

    /**
     * Number of products indexed by the agent; 0 when unknown
     *
     * @return int
     */
    public function getProductsCount(): int
    {
        return $this->integration()->getIndexedProductCount();
    }

    /**
     * The number of products, as shown
     *
     * @return string
     */
    public function getProductsCountLabel(): string
    {
        return number_format($this->getProductsCount());
    }

    /**
     * URL of the indexed products in the account; empty when there is no workspace
     *
     * @return string
     */
    public function getProductsUrl(): string
    {
        return $this->integration()->getAccountUrl('/products');
    }

    /**
     * Whether order tracking is on
     *
     * @return bool
     */
    public function isOrderEnabled(): bool
    {
        return $this->integration()->getConnection()->isOrderEnabled();
    }

    /**
     * Whether the knowledge base of the agent could not be read
     *
     * @return bool
     */
    public function hasKbError(): bool
    {
        return $this->knowledgeBase()['error'];
    }

    /**
     * Knowledge base entries of the agent: id, title, is_active, edit_url
     *
     * @return array
     */
    public function getKbEntries(): array
    {
        return $this->knowledgeBase()['entries'];
    }

    /**
     * How many of the entries are active, as a sentence
     *
     * @return string
     */
    public function getKbSummary(): string
    {
        $entries = $this->getKbEntries();
        $active = 0;
        foreach ($entries as $entry) {
            if (!empty($entry['is_active'])) {
                $active++;
            }
        }

        return (string) __('%1 of %2 entries active', $active, count($entries));
    }

    /**
     * URL where an entry is added in the account; empty when there is no workspace
     *
     * @return string
     */
    public function getKbCreateUrl(): string
    {
        return $this->integration()->getAccountUrl('/knowledge-base/create');
    }

    /**
     * Knowledge base of the agent, read once
     *
     * @return array ['entries' => array, 'error' => bool]
     */
    private function knowledgeBase(): array
    {
        if ($this->knowledgeBase === null) {
            $result = $this->integration()->getKbEntries();
            $this->knowledgeBase = [
                'entries' => isset($result['entries']) && is_array($result['entries']) ? $result['entries'] : [],
                'error' => !empty($result['error']),
            ];
        }

        return $this->knowledgeBase;
    }

    /**
     * Integration of the shop
     *
     * @return Integration
     */
    private function integration(): Integration
    {
        return $this->integrationFactory->create();
    }
}
