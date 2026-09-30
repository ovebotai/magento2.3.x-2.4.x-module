<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\StoreContext;

use Magento\Framework\UrlFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Ovebot\Chat\Model\StoreContext\StoreView;
use PHPUnit\Framework\TestCase;

class StoreViewTest extends TestCase
{
    /**
     * @dataProvider baseUrls
     */
    public function testDomain(string $baseUrl, string $expected)
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);

        $storeView = new StoreView($store, $this->createMock(UrlFactory::class));

        $this->assertSame($expected, $storeView->getDomain());
    }

    public static function baseUrls(): array
    {
        return [
            'plain' => ['https://magazin.ro/', 'magazin.ro'],
            'subfolder' => ['https://magazin.ro/shop/', 'magazin.ro'],
            'store code in path' => ['http://shop.example.test/ro/', 'shop.example.test'],
            'port is left out' => ['http://localhost:8080/', 'localhost'],
            'ipv6' => ['http://[::1]:8080/', '[::1]'],
            'upper case' => ['https://Magazin.RO/', 'magazin.ro'],
            'credentials' => ['https://user:pass@magazin.ro/', 'magazin.ro'],
            'no trailing slash' => ['https://magazin.ro', 'magazin.ro'],
            'not a url' => ['magazin.ro', ''],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider slugs
     */
    public function testDomainSlug(string $baseUrl, string $expected)
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);

        $storeView = new StoreView($store, $this->createMock(UrlFactory::class));

        $this->assertSame($expected, $storeView->getDomainSlug());
    }

    public static function slugs(): array
    {
        return [
            'www is dropped' => ['https://www.shop-test.ro/', 'shop_test_ro'],
            'subdomain' => ['http://shop.example.test/', 'shop_example_test'],
            'upper case' => ['https://Magazin.RO/', 'magazin_ro'],
            'no host' => ['', 'shop'],
            'very long host' => ['https://' . str_repeat('a', 150) . '.ro/', str_repeat('a', 100)],
        ];
    }

    public function testRouteUrlsUseTheScopeOfTheStoreView()
    {
        $store = $this->createMock(Store::class);

        $url = $this->createMock(UrlInterface::class);
        $url->expects($this->once())->method('setScope')->with($store);
        $url->method('getUrl')->willReturnCallback(function ($route, $params) {
            return $route . '|' . json_encode($params);
        });

        $urlFactory = $this->createMock(UrlFactory::class);
        $urlFactory->expects($this->once())->method('create')->willReturn($url);

        $storeView = new StoreView($store, $urlFactory);

        $this->assertSame(
            'ovebot/feed/index|{"_nosid":true,"_query":{"hash":"abc"}}',
            $storeView->getFeedUrl('abc')
        );
        $this->assertSame('ovebot/orders/index|{"_nosid":true}', $storeView->getOrdersUrl());
        $this->assertSame('ovebot/oauth/callback|{"_nosid":true}', $storeView->getOauthCallbackUrl());
        $this->assertSame('ovebot/preview/validate|{"_nosid":true}', $storeView->getPreviewValidateUrl());
        $this->assertSame('|{"_direct":"livrare","_nosid":true}', $storeView->getCmsPageUrl('livrare'));
    }

    public function testStoreAndWebsiteIdsAreNumbers()
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn('1');
        $store->method('getWebsiteId')->willReturn('2');

        $storeView = new StoreView($store, $this->createMock(UrlFactory::class));

        $this->assertSame(1, $storeView->getStoreId());
        $this->assertSame(2, $storeView->getWebsiteId());
    }
}
