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
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\IntegrationFactory;
use Ovebot\Chat\Model\Widget\Settings as WidgetSettings;

/**
 * Data of the settings page: the switches, the endpoint URLs and their keys, the appearance of the widget.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class Settings implements ArgumentInterface
{
    /**
     * Names of the widget languages, each in its own language
     */
    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'ro' => 'Română',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'es' => 'Español',
    ];

    // \z, not $: in PHP "$" also matches before a trailing line break
    private const FIELD_ID_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}\z/';

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
     * @var WidgetSettings
     */
    private $widgetSettings;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @param IntegrationFactory $integrationFactory
     * @param UrlInterface $url
     * @param FormKey $formKey
     * @param WidgetSettings $widgetSettings
     * @param RequestInterface $request
     */
    public function __construct(
        IntegrationFactory $integrationFactory,
        UrlInterface $url,
        FormKey $formKey,
        WidgetSettings $widgetSettings,
        RequestInterface $request
    ) {
        $this->integrationFactory = $integrationFactory;
        $this->url = $url;
        $this->formKey = $formKey;
        $this->widgetSettings = $widgetSettings;
        $this->request = $request;
    }

    /**
     * URL of the dashboard
     *
     * @return string
     */
    public function getDashboardUrl(): string
    {
        return $this->url->getUrl('ovebot_chat/dashboard/index');
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
     * Whether the chat widget is switched on
     *
     * @return bool
     */
    public function isChatEnabled(): bool
    {
        return $this->integration()->getConnection()->isChatEnabled();
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
     * Whether the chat offers an "Add to cart" button and sees the cart
     *
     * @return bool
     */
    public function isAddToCart(): bool
    {
        return $this->integration()->getConnection()->isAddToCart();
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
     * Whether order tracking is on
     *
     * @return bool
     */
    public function isOrderEnabled(): bool
    {
        return $this->integration()->getConnection()->isOrderEnabled();
    }

    /**
     * URL of the agent settings in the account, where a feed of the merchant is set; empty without a workspace
     *
     * @return string
     */
    public function getAgentSetupUrl(): string
    {
        return $this->integration()->getAgentSetupUrl();
    }

    /**
     * URL of the product feed, with its hash
     *
     * @return string
     */
    public function getFeedUrl(): string
    {
        $integration = $this->integration();

        return $integration->getStoreView()->getFeedUrl($integration->getConnection()->getFeedHash());
    }

    /**
     * URL of the order endpoint
     *
     * @return string
     */
    public function getOrdersUrl(): string
    {
        return $this->integration()->getStoreView()->getOrdersUrl();
    }

    /**
     * User of the order endpoint
     *
     * @return string
     */
    public function getOrderUser(): string
    {
        return $this->integration()->getConnection()->getOrderUser();
    }

    /**
     * Password of the order endpoint
     *
     * @return string
     */
    public function getOrderPass(): string
    {
        return $this->integration()->getConnection()->getOrderPass();
    }

    /**
     * Appearance of the widget: every key, empty where nothing was set
     *
     * @return string[]
     */
    public function getWidget(): array
    {
        return $this->widgetSettings->withDefaults($this->integration()->getConnection()->getWidget());
    }

    /**
     * Value of one appearance setting
     *
     * @param string $key
     * @return string
     */
    public function getWidgetValue(string $key): string
    {
        $widget = $this->getWidget();

        return isset($widget[$key]) ? $widget[$key] : '';
    }

    /**
     * What the chat loader uses when a setting is left empty; empty when there is no such default
     *
     * @param string $key
     * @return string
     */
    public function getPlaceholder(string $key): string
    {
        $placeholders = $this->widgetSettings->placeholders();

        return isset($placeholders[$key]) ? $placeholders[$key] : '';
    }

    /**
     * Upper limit of a setting: the largest number, or the longest text; 0 when the setting has none
     *
     * @param string $key
     * @return int
     */
    public function getLimit(string $key): int
    {
        $limits = [
            'offset_y' => WidgetSettings::MAX_OFFSET,
            'offset_x' => WidgetSettings::MAX_OFFSET,
            'z_index' => WidgetSettings::MAX_Z_INDEX,
            'proactive_delay' => WidgetSettings::MAX_PROACTIVE_DELAY,
            'subtitle' => WidgetSettings::MAX_TEXT_LENGTH,
            'proactive_message' => WidgetSettings::MAX_TEXT_LENGTH,
        ];

        return isset($limits[$key]) ? $limits[$key] : 0;
    }

    /**
     * Colour shown by the colour picker: the chosen one, or the default of the chat loader
     *
     * @return string
     */
    public function getPickerColor(): string
    {
        $color = $this->getWidgetValue('accent_color');

        return $color !== '' ? $color : $this->getPlaceholder('accent_color');
    }

    /**
     * Choices of the theme list: value => label
     *
     * @return string[]
     */
    public function getThemeOptions(): array
    {
        return [
            '' => (string) __('Default (light)'),
            'light' => (string) __('Light'),
            'dark' => (string) __('Dark'),
        ];
    }

    /**
     * Choices of the language list: value => label
     *
     * @return string[]
     */
    public function getLanguageOptions(): array
    {
        $options = [
            '' => (string) __('Default'),
            'auto' => (string) __('Auto (browser)'),
        ];
        foreach (WidgetSettings::LANGUAGES as $code) {
            if (isset(self::LANGUAGE_NAMES[$code])) {
                $options[$code] = self::LANGUAGE_NAMES[$code];
            }
        }

        return $options;
    }

    /**
     * Choices of the sound list: value => label
     *
     * @return string[]
     */
    public function getAudioOptions(): array
    {
        return [
            '' => (string) __('Default (play)'),
            'play' => (string) __('Play'),
            'none' => (string) __('None'),
        ];
    }

    /**
     * Choices of the position list: value => label
     *
     * @return string[]
     */
    public function getSideOptions(): array
    {
        return [
            '' => (string) __('Default (right)'),
            'right' => (string) __('Bottom right'),
            'left' => (string) __('Bottom left'),
        ];
    }

    /**
     * Id of the field a link pointed at (".../highlight/oveChatStatus"); empty when there is none
     *
     * @return string
     */
    public function getHighlight(): string
    {
        $field = $this->request->getParam('highlight', '');
        $field = is_scalar($field) ? (string) $field : '';

        return preg_match(self::FIELD_ID_PATTERN, $field) ? $field : '';
    }

    /**
     * Configuration of the settings script, as JSON that is safe inside a script tag
     *
     * @return string
     */
    public function getJsConfigJson(): string
    {
        $config = [
            'formKey' => $this->formKey->getFormKey(),
            'dashboardUrl' => $this->getDashboardUrl(),
            'isConnected' => $this->integration()->isConnected(),
            'highlight' => $this->getHighlight(),
            'ajaxUrls' => [
                'SaveSettings' => $this->url->getUrl('ovebot_chat/ajax/saveSettings'),
                'RegenFeedHash' => $this->url->getUrl('ovebot_chat/ajax/regenFeedHash'),
                'RegenOrderCreds' => $this->url->getUrl('ovebot_chat/ajax/regenOrderCreds'),
            ],
            'i18n' => [
                'saved' => (string) __('Settings saved.'),
                'saving' => (string) __('Saving…'),
                'error' => (string) __('An error occurred. Please try again.'),
                'enabled' => (string) __('Enabled'),
                'disabled' => (string) __('Disabled'),
                'confirmRegenHash' => (string) __('Regenerate feed hash? The current feed URL will stop working.'),
                'confirmRegenCreds' => (string) __(
                    'Regenerate API credentials? The current credentials will stop working immediately.'
                ),
                'copied' => (string) __('Copied!'),
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
