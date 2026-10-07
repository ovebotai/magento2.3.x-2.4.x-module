<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\ViewModel\Adminhtml;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\FormKey;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\IntegrationFactory;
use Ovebot\Chat\Model\StoreContext\StoreView;
use Ovebot\Chat\Model\Widget\Settings as WidgetSettings;
use Ovebot\Chat\ViewModel\Adminhtml\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    /**
     * @param array $widget what the connection holds
     * @param mixed $highlight the "highlight" parameter of the request
     * @return Settings
     */
    private function settings(array $widget = [], $highlight = null): Settings
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getWidget')->willReturn($widget);
        $connection->method('getFeedHash')->willReturn(str_repeat('a', 32));
        $connection->method('getOrderUser')->willReturn('shop_test_ro_deadbeef');
        $connection->method('getOrderPass')->willReturn(str_repeat('b', 32));
        $connection->method('isChatEnabled')->willReturn(true);
        $connection->method('isProductsBuiltin')->willReturn(false);
        $connection->method('isProductsRecommend')->willReturn(true);
        $connection->method('isOrderEnabled')->willReturn(false);
        $connection->method('isAddToCart')->willReturn(false);

        $storeView = $this->createMock(StoreView::class);
        $storeView->method('getFeedUrl')->willReturnCallback(function ($hash) {
            return 'https://www.shop-test.ro/ovebot/feed/index/?hash=' . $hash;
        });
        $storeView->method('getOrdersUrl')->willReturn('https://www.shop-test.ro/ovebot/orders/index/');

        $integration = $this->createMock(Integration::class);
        $integration->method('getConnection')->willReturn($connection);
        $integration->method('getStoreView')->willReturn($storeView);
        $integration->method('isConnected')->willReturn(true);
        $integration->method('getAgentSetupUrl')->willReturn('https://my-shop.ovebot.ai/setup');

        $factory = $this->createMock(IntegrationFactory::class);
        $factory->method('create')->willReturn($integration);

        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(function ($route, $params = []) {
            return 'admin|' . $route . '|' . json_encode($params ?: []);
        });

        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')->willReturn('KEY123');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(function ($name, $default = null) use ($highlight) {
            return $name === 'highlight' && $highlight !== null ? $highlight : $default;
        });

        return new Settings($factory, $url, $formKey, new WidgetSettings(), $request);
    }

    public function testSwitchesAndEndpoints()
    {
        $settings = $this->settings();

        $this->assertTrue($settings->isChatEnabled());
        $this->assertFalse($settings->isProductsBuiltin());
        $this->assertTrue($settings->isProductsRecommend());
        $this->assertFalse($settings->isOrderEnabled());
        $this->assertFalse($settings->isAddToCart());
        $this->assertSame(
            'https://www.shop-test.ro/ovebot/feed/index/?hash=' . str_repeat('a', 32),
            $settings->getFeedUrl()
        );
        $this->assertSame('https://www.shop-test.ro/ovebot/orders/index/', $settings->getOrdersUrl());
        $this->assertSame('shop_test_ro_deadbeef', $settings->getOrderUser());
        $this->assertSame(str_repeat('b', 32), $settings->getOrderPass());
        $this->assertSame('https://my-shop.ovebot.ai/setup', $settings->getAgentSetupUrl());
    }

    public function testLinks()
    {
        $settings = $this->settings();

        $this->assertSame('admin|ovebot_chat/dashboard/index|[]', $settings->getDashboardUrl());
        $this->assertSame('admin|ovebot_chat/dashboard/index|{"view":"settings"}', $settings->getSettingsUrl());
    }

    public function testWidgetValuesAndPlaceholders()
    {
        $settings = $this->settings(['theme' => 'dark', 'offset_y' => 35, 'other' => 'x']);

        $this->assertCount(11, $settings->getWidget());
        $this->assertSame('dark', $settings->getWidgetValue('theme'));
        $this->assertSame('35', $settings->getWidgetValue('offset_y'));
        $this->assertSame('', $settings->getWidgetValue('language'));
        $this->assertSame('', $settings->getWidgetValue('other'));
        $this->assertSame('20', $settings->getPlaceholder('offset_x'));
        $this->assertSame('', $settings->getPlaceholder('theme'));
    }

    public function testPickerShowsTheDefaultColourUntilOneIsChosen()
    {
        $this->assertSame('#615ED6', $this->settings()->getPickerColor());
        $this->assertSame('#FF0000', $this->settings(['accent_color' => '#FF0000'])->getPickerColor());
    }

    public function testLimits()
    {
        $settings = $this->settings();

        $this->assertSame(10000, $settings->getLimit('offset_y'));
        $this->assertSame(10000, $settings->getLimit('offset_x'));
        $this->assertSame(2147483647, $settings->getLimit('z_index'));
        $this->assertSame(300, $settings->getLimit('proactive_delay'));
        $this->assertSame(255, $settings->getLimit('subtitle'));
        $this->assertSame(255, $settings->getLimit('proactive_message'));
        $this->assertSame(0, $settings->getLimit('theme'));
    }

    public function testListsOfferExactlyTheValuesThatAreAccepted()
    {
        $settings = $this->settings();

        $this->assertSame(WidgetSettings::THEMES, array_map('strval', array_keys($settings->getThemeOptions())));
        $this->assertSame(
            WidgetSettings::LANGUAGES,
            array_map('strval', array_keys($settings->getLanguageOptions()))
        );
        $this->assertSame(WidgetSettings::AUDIO, array_map('strval', array_keys($settings->getAudioOptions())));
        $this->assertSame(WidgetSettings::SIDES, array_map('strval', array_keys($settings->getSideOptions())));
        $this->assertSame('Română', $settings->getLanguageOptions()['ro']);
    }

    /**
     * @dataProvider highlights
     * @param mixed $given
     * @param string $expected
     */
    public function testHighlight($given, string $expected)
    {
        $this->assertSame($expected, $this->settings([], $given)->getHighlight());
    }

    public static function highlights(): array
    {
        return [
            'none' => [null, ''],
            'field id' => ['oveChatStatus', 'oveChatStatus'],
            'field id with underscore' => ['ove_accent_color', 'ove_accent_color'],
            'selector' => ['oveChatStatus, body', ''],
            'markup' => ['"><script>', ''],
            'starts with a digit' => ['1abc', ''],
            'line break at the end' => ["oveChatStatus\n", ''],
            'array' => [['oveChatStatus'], ''],
            'too long' => [str_repeat('a', 65), ''],
        ];
    }

    public function testScriptConfiguration()
    {
        $json = $this->settings([], 'oveOrderEnabled')->getJsConfigJson();
        $config = json_decode($json, true);

        $this->assertSame('KEY123', $config['formKey']);
        $this->assertTrue($config['isConnected']);
        $this->assertSame('oveOrderEnabled', $config['highlight']);
        $this->assertSame('admin|ovebot_chat/dashboard/index|[]', $config['dashboardUrl']);
        $this->assertSame(
            [
                'SaveSettings' => 'admin|ovebot_chat/ajax/saveSettings|[]',
                'RegenFeedHash' => 'admin|ovebot_chat/ajax/regenFeedHash|[]',
                'RegenOrderCreds' => 'admin|ovebot_chat/ajax/regenOrderCreds|[]',
            ],
            $config['ajaxUrls']
        );
        $this->assertSame(
            ['saved', 'saving', 'error', 'enabled', 'disabled', 'confirmRegenHash', 'confirmRegenCreds', 'copied'],
            array_keys($config['i18n'])
        );
        // the keys of the endpoints are in the form, not in the configuration of the script
        $this->assertStringNotContainsString(str_repeat('b', 32), $json);
        // safe inside a script tag
        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);
    }
}
