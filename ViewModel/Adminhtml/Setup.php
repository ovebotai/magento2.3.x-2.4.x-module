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
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ovebot\Chat\Model\Feed\ProductCounter;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\IntegrationFactory;
use Ovebot\Chat\Model\KnowledgeBase\CmsPageProvider;

/**
 * Data of the setup wizard: 1 Connect account, 2 Website pages, 3 Products, 4 Go live.
 */
class Setup implements ArgumentInterface
{
    public const STEP_CONNECT = 1;
    public const STEP_PAGES = 2;
    public const STEP_PRODUCTS = 3;
    public const STEP_GO_LIVE = 4;

    /**
     * @var IntegrationFactory
     */
    private $integrationFactory;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var FormKey
     */
    private $formKey;

    /**
     * @var CmsPageProvider
     */
    private $cmsPageProvider;

    /**
     * @var ProductCounter
     */
    private $productCounter;

    /**
     * @var array|null pages of the wizard, once read
     */
    private $pages;

    /**
     * @param IntegrationFactory $integrationFactory
     * @param UrlInterface $url
     * @param FormKey $formKey
     * @param CmsPageProvider $cmsPageProvider
     * @param ProductCounter $productCounter
     */
    public function __construct(
        IntegrationFactory $integrationFactory,
        UrlInterface $url,
        FormKey $formKey,
        CmsPageProvider $cmsPageProvider,
        ProductCounter $productCounter
    ) {
        $this->integrationFactory = $integrationFactory;
        $this->url = $url;
        $this->formKey = $formKey;
        $this->cmsPageProvider = $cmsPageProvider;
        $this->productCounter = $productCounter;
    }

    /**
     * Step the wizard opens on. It comes from the state only, never from the query string.
     *
     * @return int
     */
    public function getStep(): int
    {
        return $this->isConnected() ? self::STEP_PAGES : self::STEP_CONNECT;
    }

    /**
     * Labels of the steps, by step number
     *
     * @return string[]
     */
    public function getStepLabels(): array
    {
        return [
            self::STEP_CONNECT => (string) __('Connect account'),
            self::STEP_PAGES => (string) __('Website pages'),
            self::STEP_PRODUCTS => (string) __('Products'),
            self::STEP_GO_LIVE => (string) __('Go live'),
        ];
    }

    /**
     * Whether the shop is connected
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->integration()->isConnected();
    }

    /**
     * CMS pages to choose from: id, title, url, checked
     *
     * @return array
     */
    public function getPages(): array
    {
        $integration = $this->integration();
        if (!$integration->isConnected()) {
            // step 2 cannot be reached without a connection
            return [];
        }

        if ($this->pages === null) {
            $this->pages = $this->cmsPageProvider->listForWizard($integration->getKbPageIds());
        }

        return $this->pages;
    }

    /**
     * Whether the built-in feed is the product source
     *
     * @return bool
     */
    public function isProductsBuiltin(): bool
    {
        return $this->integration()->getConnection()->isProductsBuiltin();
    }

    /**
     * URL of the knowledge base in the merchant's Ovebot.ai account
     *
     * @return string
     */
    public function getKnowledgeBaseUrl(): string
    {
        return $this->integration()->getAccountUrl('/knowledge-base');
    }

    /**
     * URL of the agent settings in the merchant's Ovebot.ai account
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
     * @return string
     */
    public function getSettingsUrl(): string
    {
        return $this->url->getUrl('ovebot_chat/dashboard/index', ['view' => 'settings']);
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
     * What the built-in feed sends: {total, feed_count}
     *
     * Counted only for a connected shop, the only one that reaches the products step. Null when the count
     * failed; the wizard then shows the step without a number.
     *
     * @return array|null
     */
    public function getProductCounts(): ?array
    {
        return $this->isConnected() ? $this->productCounter->counts() : null;
    }

    /**
     * Configuration of the wizard script, as JSON that is safe inside a script tag
     *
     * @return string
     */
    public function getJsConfigJson(): string
    {
        $config = [
            'initialStep' => $this->getStep(),
            'isConnected' => $this->isConnected(),
            'stepsSequence' => array_keys($this->getStepLabels()),
            'formKey' => $this->formKey->getFormKey(),
            'ajaxUrls' => [
                'SyncPages' => $this->url->getUrl('ovebot_chat/ajax/syncPages'),
                'Finish' => $this->url->getUrl('ovebot_chat/ajax/finish'),
            ],
            'productCounts' => $this->getProductCounts(),
            'i18n' => [
                'next' => (string) __('Next'),
                'finish' => (string) __('Finish setup'),
                'retry' => (string) __('Retry'),
                'error' => (string) __('An error occurred. Please try again.'),
                'noProducts' => (string) __(
                    "No enabled products found - your AI agent won't have any products to recommend yet."
                ),
                'productsWillBeIndexed' => (string) __(
                    'products will be sent to your AI agent so it can recommend them to customers.'
                ),
                'syncingPages' => (string) __('Syncing pages…'),
                'kbLimitPageSkipped' => (string) __('Skipped - knowledge base limit reached.'),
                'pageUpdated' => (string) __('Saved'),
            ],
        ];

        return (string) json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
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
