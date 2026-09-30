<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Ovebot\Chat\Model\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @param array $values path => value
     * @return Config
     */
    private function config(array $values): Config
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function ($path, $scopeType = null, $scopeCode = null) use ($values) {
                // the default level: the options are not read for a website or a store view
                $this->assertSame(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeType);
                $this->assertNull($scopeCode);

                return isset($values[$path]) ? $values[$path] : null;
            }
        );

        return new Config($scopeConfig, new Json());
    }

    /**
     * @dataProvider maxAges
     */
    public function testMaxOrderAgeDays($stored, int $expected)
    {
        $config = $this->config([Config::XML_PATH_MAX_AGE_DAYS => $stored]);

        $this->assertSame($expected, $config->getMaxOrderAgeDays());
    }

    public static function maxAges(): array
    {
        return [
            'not set' => [null, 60],
            'empty' => ['', 60],
            'zero' => ['0', 60],
            'negative' => ['-5', 60],
            'text' => ['abc', 60],
            'value' => ['90', 90],
        ];
    }

    public function testTrackingFinderCodes()
    {
        $this->assertSame([], $this->config([])->getTrackingFinderCodes());
        $this->assertSame(
            ['native', 'sameday'],
            $this->config([Config::XML_PATH_TRACKING_FINDERS => 'native, sameday,,native'])->getTrackingFinderCodes()
        );
    }

    public function testTrackingUrlTemplates()
    {
        $stored = json_encode([
            '_1_a' => ['code' => ' SameDay ', 'url' => 'https://sameday.ro/#awb={code}'],
            '_2_b' => ['code' => 'dpd', 'url' => ''],
            '_3_c' => ['code' => '', 'url' => 'https://example.com/{code}'],
            '_4_d' => 'not a row',
        ]);

        $this->assertSame(
            ['sameday' => 'https://sameday.ro/#awb={code}'],
            $this->config([Config::XML_PATH_TRACKING_URLS => $stored])->getTrackingUrlTemplates()
        );
        $this->assertSame([], $this->config([])->getTrackingUrlTemplates());
        $this->assertSame([], $this->config([Config::XML_PATH_TRACKING_URLS => '{broken'])->getTrackingUrlTemplates());
    }

    public function testHosts()
    {
        $config = $this->config([Config::XML_PATH_ACCOUNT_HOST => ' account.staging.test ']);

        $this->assertSame('account.staging.test', $config->getAccountHost());
        $this->assertSame('', $config->getApiHost());
    }
}
