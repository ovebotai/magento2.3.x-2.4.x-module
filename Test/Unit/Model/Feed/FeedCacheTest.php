<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Feed;

use Magento\Framework\Lock\LockManagerInterface;
use Ovebot\Chat\Model\Feed\FeedCache;
use Ovebot\Chat\Model\Feed\FeedFileWriter;
use Ovebot\Chat\Model\Feed\ProductFeedBuilder;
use Ovebot\Chat\Model\StoreEmulator;
use PHPUnit\Framework\TestCase;

class FeedCacheTest extends TestCase
{
    private const OLD = 'ovebot_chat/feed/feed-old.json';

    private const WRITTEN = 'ovebot_chat/feed/feed-new.json';

    /**
     * @var array[] what latest() answers, call after call; the last answer is repeated
     */
    private $latest = [];

    /**
     * @var bool[] what lock() answers, call after call
     */
    private $locks = [];

    /**
     * @var \Exception|null raised while the feed is written
     */
    private $failure;

    /**
     * @var string[] what was done, in order
     */
    private $steps = [];

    protected function setUp(): void
    {
        $this->latest = [null];
        $this->locks = [true];
        $this->failure = null;
        $this->steps = [];
    }

    private function cache(): FeedCache
    {
        $files = $this->createMock(FeedFileWriter::class);
        $files->method('latest')->willReturnCallback(function () {
            return count($this->latest) > 1 ? array_shift($this->latest) : $this->latest[0];
        });
        $files->method('clean')->willReturnCallback(function ($keep = '') {
            $this->steps[] = 'clean, keep ' . ($keep !== '' ? $keep : 'nothing');
        });
        $files->method('write')->willReturnCallback(function ($items) {
            $this->steps[] = 'write ' . implode(',', array_column(iterator_to_array($items, false), 'ref'));
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return self::WRITTEN;
        });

        $builder = $this->createMock(ProductFeedBuilder::class);
        $builder->method('iterate')->willReturnCallback(function () {
            yield ['ref' => 'A-1'];
            yield ['ref' => 'A-2'];
        });

        $emulator = $this->createMock(StoreEmulator::class);
        $emulator->method('run')->willReturnCallback(function (callable $callback) {
            $this->steps[] = 'storefront on';
            try {
                return $callback();
            } finally {
                $this->steps[] = 'storefront off';
            }
        });

        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->willReturnCallback(function ($name, $timeout) {
            $this->assertSame('ovebot_chat_feed', $name);
            $taken = (bool) array_shift($this->locks);
            $this->steps[] = 'lock, wait ' . $timeout . ': ' . ($taken ? 'taken' : 'busy');

            return $taken;
        });
        $lock->method('unlock')->willReturnCallback(function ($name) {
            $this->assertSame('ovebot_chat_feed', $name);
            $this->steps[] = 'unlock';

            return true;
        });

        return new FeedCache($files, $builder, $emulator, $lock);
    }

    private function feed(string $path, int $age): array
    {
        return ['path' => $path, 'time' => time() - $age];
    }

    public function testRecentFeedIsSentAsItIs()
    {
        $this->latest = [$this->feed(self::OLD, FeedCache::MAX_AGE - 60)];

        $this->assertSame(self::OLD, $this->cache()->path());
        $this->assertSame([], $this->steps, 'no lock and no writing for a feed that is reused');
    }

    public function testFirstRequestWritesTheFeed()
    {
        $this->assertSame(self::WRITTEN, $this->cache()->path());
        $this->assertSame(
            [
                'lock, wait 0: taken',
                'clean, keep nothing',
                'storefront on',
                'write A-1,A-2',
                'storefront off',
                'clean, keep ' . self::WRITTEN,
                'unlock',
            ],
            $this->steps
        );
    }

    public function testOldFeedIsReplacedAndRemovedOnlyAfterTheNewOneIsComplete()
    {
        $this->latest = [$this->feed(self::OLD, FeedCache::MAX_AGE + 60)];

        $this->assertSame(self::WRITTEN, $this->cache()->path());
        $this->assertSame(
            [
                'lock, wait 0: taken',
                'clean, keep ' . self::OLD,
                'storefront on',
                'write A-1,A-2',
                'storefront off',
                'clean, keep ' . self::WRITTEN,
                'unlock',
            ],
            $this->steps
        );
    }

    public function testOldFeedIsSentWhileAnotherRequestWritesTheNewOne()
    {
        $this->latest = [$this->feed(self::OLD, FeedCache::MAX_AGE + 60)];
        $this->locks = [false];

        $this->assertSame(self::OLD, $this->cache()->path());
        $this->assertSame(['lock, wait 0: busy'], $this->steps);
    }

    public function testWithoutAnyFeedTheRequestWaitsForTheOneBeingWritten()
    {
        $this->latest = [null, $this->feed(self::WRITTEN, 1)];
        $this->locks = [false, true];

        $this->assertSame(self::WRITTEN, $this->cache()->path());
        $this->assertSame(
            ['lock, wait 0: busy', 'lock, wait ' . FeedCache::WAIT . ': taken', 'unlock'],
            $this->steps,
            'the feed written meanwhile is not written a second time'
        );
    }

    public function testFeedThatDoesNotGetReadyInTimeGivesNoPath()
    {
        $this->locks = [false, false];

        $this->assertNull($this->cache()->path());
        $this->assertSame(['lock, wait 0: busy', 'lock, wait ' . FeedCache::WAIT . ': busy'], $this->steps);
    }

    public function testRequestThatWaitedWritesTheFeedWhenTheOtherOneFailed()
    {
        $this->locks = [false, true];

        $this->assertSame(self::WRITTEN, $this->cache()->path());
        $this->assertContains('write A-1,A-2', $this->steps);
        $this->assertSame('unlock', end($this->steps));
    }

    public function testFailureKeepsTheOldFeedAndFreesTheLock()
    {
        $this->latest = [$this->feed(self::OLD, FeedCache::MAX_AGE + 60)];
        $this->failure = new \RuntimeException('index table is locked');

        try {
            $this->cache()->path();
            $this->fail('path() did not raise');
        } catch (\RuntimeException $e) {
            $this->assertSame('index table is locked', $e->getMessage());
        }

        $this->assertSame(
            [
                'lock, wait 0: taken',
                'clean, keep ' . self::OLD,
                'storefront on',
                'write A-1,A-2',
                'storefront off',
                'unlock',
            ],
            $this->steps
        );
    }
}
