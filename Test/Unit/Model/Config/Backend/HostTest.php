<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Ovebot\Chat\Model\Config\Backend\Host;
use PHPUnit\Framework\TestCase;

class HostTest extends TestCase
{
    /**
     * @param mixed $value
     * @return Host
     */
    private function host($value): Host
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $host = new Host(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class)
        );
        $host->setValue($value);

        return $host;
    }

    /**
     * @dataProvider accepted
     * @param mixed $value
     * @param string $stored
     */
    public function testHostNamesAreKept($value, string $stored)
    {
        $host = $this->host($value);

        $host->beforeSave();

        $this->assertSame($stored, $host->getValue());
    }

    public static function accepted(): array
    {
        return [
            'empty' => ['', ''],
            'nothing' => [null, ''],
            'spaces only' => ['   ', ''],
            'host' => ['api.ovebot.ai', 'api.ovebot.ai'],
            'host with spaces around' => [' api.staging.ovebot.ai ', 'api.staging.ovebot.ai'],
            'host with port' => ['localhost:8443', 'localhost:8443'],
        ];
    }

    /**
     * @dataProvider refused
     * @param string $value
     */
    public function testAnythingElseIsRefused(string $value)
    {
        $this->expectException(LocalizedException::class);

        $this->host($value)->beforeSave();
    }

    public static function refused(): array
    {
        return [
            'with protocol' => ['https://api.ovebot.ai'],
            'with path' => ['api.ovebot.ai/v1'],
            'with user' => ['user@api.ovebot.ai'],
            'with query' => ['api.ovebot.ai?x=1'],
            'with a space inside' => ['api ovebot.ai'],
            'with a line break inside' => ["api.ovebot.ai\nevil.test"],
            'starts with a dash' => ['-api.ovebot.ai'],
        ];
    }
}
