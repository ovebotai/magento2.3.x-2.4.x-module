<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Security;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ovebot\Chat\Model\Security\ReturnUrlValidator;
use PHPUnit\Framework\TestCase;

class ReturnUrlValidatorTest extends TestCase
{
    /**
     * @param string $customAdminUrl empty when the custom admin URL is off
     * @return ReturnUrlValidator
     */
    private function validator(string $customAdminUrl = ''): ReturnUrlValidator
    {
        $stores = [];
        foreach (['http://shop.example.test/', 'https://shop.example.com/ro/'] as $baseUrl) {
            $store = $this->createMock(Store::class);
            $store->method('getBaseUrl')->willReturnCallback(function ($type, $secure) use ($baseUrl) {
                return $secure ? str_replace('http://', 'https://', $baseUrl) : $baseUrl;
            });
            $stores[] = $store;
        }

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->any())->method('getStores')->with(true)->willReturn($stores);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn($customAdminUrl !== '');
        $scopeConfig->method('getValue')->willReturn($customAdminUrl);

        return new ReturnUrlValidator($storeManager, $scopeConfig);
    }

    /**
     * @dataProvider urls
     */
    public function testIsAllowed(string $url, bool $expected)
    {
        $this->assertSame($expected, $this->validator()->isAllowed($url));
    }

    public static function urls(): array
    {
        $admin = 'http://shop.example.test/admin_x/ovebot_chat/dashboard/index/store/1/key/abc/';

        return [
            'admin url' => [$admin, true],
            'https on the same host' => [str_replace('http://', 'https://', $admin), true],
            'another store of the installation' => ['https://shop.example.com/admin/', true],
            'upper case host' => ['http://SHOP.example.test/admin/', true],
            'with port' => ['http://shop.example.test:8080/admin/', true],
            'foreign host' => ['https://evil.example/admin/', false],
            'known host as subdomain of a foreign one' => ['https://shop.example.test.evil.example/', false],
            'known host in the credentials' => ['https://shop.example.test@evil.example/', false],
            'known host in the path' => ['https://evil.example/shop.example.test/', false],
            'known host in the query' => ['https://evil.example/?shop.example.test', false],
            'backslash trick' => ['https://evil.example\\@shop.example.test/', false],
            'javascript' => ['javascript:alert(1)', false],
            'protocol relative' => ['//shop.example.test/admin/', false],
            'relative' => ['/admin/', false],
            'ftp' => ['ftp://shop.example.test/', false],
            'line break' => ["http://shop.example.test/\nLocation: https://evil.example/", false],
            'leading space' => [' http://shop.example.test/admin/', false],
            'empty' => ['', false],
        ];
    }

    public function testCustomAdminUrl()
    {
        $url = 'https://backoffice.example.com/admin_x/ovebot_chat/dashboard/index/';

        $this->assertFalse($this->validator()->isAllowed($url));
        $this->assertTrue($this->validator('https://backoffice.example.com/')->isAllowed($url));
    }
}
