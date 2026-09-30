<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Cache;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use Ovebot\Chat\Block\Widget;
use Ovebot\Chat\Model\Cache\WidgetCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WidgetCacheTest extends TestCase
{
    /**
     * @var array [event name, data]
     */
    private $dispatched = [];

    /**
     * @var string[]
     */
    private $logged = [];

    /**
     * @var bool
     */
    private $dispatchFails = false;

    protected function setUp(): void
    {
        $this->dispatched = [];
        $this->logged = [];
        $this->dispatchFails = false;
    }

    private function cache(): WidgetCache
    {
        $factory = $this->getMockBuilder(CacheContextFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(function () {
            return new CacheContext();
        });

        $events = $this->createMock(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function ($name, array $data = []) {
            if ($this->dispatchFails) {
                throw new \RuntimeException('Varnish is down');
            }
            $this->dispatched[] = [$name, $data];
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->logged[] = (string) $message;
        });

        return new WidgetCache($factory, $events, $logger);
    }

    public function testCleansTheTagOfTheWidgetThroughTheCacheEvent()
    {
        $this->assertTrue($this->cache()->clean());

        $this->assertCount(1, $this->dispatched);
        list($name, $data) = $this->dispatched[0];
        $this->assertSame('clean_cache_by_tags', $name);
        // the full page cache and Varnish read the tags of an IdentityInterface
        $this->assertInstanceOf(IdentityInterface::class, $data['object']);
        $this->assertSame([WidgetCache::TAG], $data['object']->getIdentities());
    }

    public function testEveryCleanHasAContextOfItsOwn()
    {
        $cache = $this->cache();
        $cache->clean();
        $cache->clean();

        $this->assertNotSame($this->dispatched[0][1]['object'], $this->dispatched[1][1]['object']);
        $this->assertSame([WidgetCache::TAG], $this->dispatched[1][1]['object']->getIdentities());
    }

    public function testTheTagIsTheOneOfTheWidgetBlock()
    {
        $block = $this->getMockBuilder(Widget::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->assertSame([WidgetCache::TAG], $block->getIdentities());
    }

    public function testAFailureIsLoggedAndNeverThrown()
    {
        $this->dispatchFails = true;

        $this->assertFalse($this->cache()->clean());
        $this->assertSame(['Widget cache not cleaned (RuntimeException).'], $this->logged);
    }
}
