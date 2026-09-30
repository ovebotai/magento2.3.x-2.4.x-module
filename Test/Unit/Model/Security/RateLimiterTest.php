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

use Ovebot\Chat\Model\ResourceModel\RateLimit as RateLimitResource;
use Ovebot\Chat\Model\Security\Random;
use Ovebot\Chat\Model\Security\RateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RateLimiterTest extends TestCase
{
    private const IP = '203.0.113.7';
    private const NOW = 1790000000;

    /**
     * @var array ip hash => row, the table
     */
    private $rows = [];

    /**
     * @var int
     */
    private $cleanups = 0;

    /**
     * @var bool
     */
    private $databaseDown = false;

    /**
     * @var int what the random draw gives
     */
    private $draw = 2;

    /**
     * @var string[]
     */
    private $logged = [];

    protected function setUp(): void
    {
        $this->rows = [];
        $this->cleanups = 0;
        $this->databaseDown = false;
        $this->draw = 2;
        $this->logged = [];
    }

    private function limiter(): RateLimiter
    {
        $down = function () {
            if ($this->databaseDown) {
                throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
            }
        };

        $resource = $this->createMock(RateLimitResource::class);
        $resource->method('getRow')->willReturnCallback(function ($hash) use ($down) {
            $down();

            return isset($this->rows[$hash]) ? $this->rows[$hash] : null;
        });
        $resource->method('saveRow')->willReturnCallback(
            function ($hash, $failures, $windowStart, $blockedUntil) use ($down) {
                $down();
                $this->rows[$hash] = [
                    'failures' => $failures,
                    'window_start' => $windowStart,
                    'blocked_until' => $blockedUntil,
                ];
            }
        );
        $resource->method('deleteRow')->willReturnCallback(function ($hash) use ($down) {
            $down();
            $found = isset($this->rows[$hash]);
            unset($this->rows[$hash]);

            return $found ? 1 : 0;
        });
        $resource->method('deleteExpired')->willReturnCallback(function ($now, $window) use ($down) {
            $down();
            $this->cleanups++;
            $before = count($this->rows);
            $this->rows = array_filter($this->rows, function ($row) use ($now, $window) {
                return $row['blocked_until'] > $now || $row['window_start'] > $now - $window;
            });

            return $before - count($this->rows);
        });

        $random = $this->createMock(Random::class);
        $random->method('number')->willReturnCallback(function () {
            return $this->draw;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->logged[] = $message;
        });

        return new RateLimiter($resource, $random, $logger);
    }

    public function testNineFailuresDoNotBlock()
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 9; $i++) {
            $limiter->recordFailure(self::IP, self::NOW + $i);
        }

        $this->assertFalse($limiter->isBlocked(self::IP, self::NOW + 10));
    }

    public function testTheTenthFailureBlocksForAnHour()
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 10; $i++) {
            $limiter->recordFailure(self::IP, self::NOW + $i);
        }

        $this->assertTrue($limiter->isBlocked(self::IP, self::NOW + 10));
        $this->assertTrue($limiter->isBlocked(self::IP, self::NOW + 9 + 3599));
        $this->assertFalse($limiter->isBlocked(self::IP, self::NOW + 9 + 3600));
        $this->assertFalse($limiter->isBlocked('203.0.113.8', self::NOW + 10), 'another client is not blocked');
    }

    public function testFailuresOfAnEndedWindowAreForgotten()
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 9; $i++) {
            $limiter->recordFailure(self::IP, self::NOW);
        }
        // the window started at NOW has ended: this failure starts a new count
        $limiter->recordFailure(self::IP, self::NOW + 3600);

        $this->assertFalse($limiter->isBlocked(self::IP, self::NOW + 3601));
        $row = $this->rows[$limiter->hash(self::IP)];
        $this->assertSame(1, $row['failures']);
        $this->assertSame(self::NOW + 3600, $row['window_start']);
    }

    public function testAfterTheBlockTheCountStartsAgain()
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 10; $i++) {
            $limiter->recordFailure(self::IP, self::NOW);
        }
        $limiter->recordFailure(self::IP, self::NOW + 3600);

        $this->assertFalse($limiter->isBlocked(self::IP, self::NOW + 3600));
        $this->assertSame(1, $this->rows[$limiter->hash(self::IP)]['failures']);
    }

    public function testSuccessForgetsTheFailures()
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 9; $i++) {
            $limiter->recordFailure(self::IP, self::NOW);
        }
        $limiter->reset(self::IP);
        $limiter->recordFailure(self::IP, self::NOW + 1);

        $this->assertFalse($limiter->isBlocked(self::IP, self::NOW + 2));
        $this->assertSame(1, $this->rows[$limiter->hash(self::IP)]['failures']);
    }

    public function testTheIpIsStoredOnlyAsAHash()
    {
        $limiter = $this->limiter();
        $limiter->recordFailure(self::IP, self::NOW);

        $this->assertSame([hash('sha256', 'ovebot_chat|' . self::IP)], array_keys($this->rows));
        $this->assertSame(64, strlen($limiter->hash(self::IP)));
        $this->assertStringNotContainsString(self::IP, json_encode($this->rows));
    }

    public function testOldRowsAreRemovedNowAndThen()
    {
        $limiter = $this->limiter();
        $this->rows[hash('sha256', 'ovebot_chat|198.51.100.1')] = [
            'failures' => 3,
            'window_start' => self::NOW - 7200,
            'blocked_until' => 0,
        ];

        $limiter->recordFailure(self::IP, self::NOW);
        $this->assertSame(0, $this->cleanups, 'no cleanup when the draw is not 1');
        $this->assertCount(2, $this->rows);

        $this->draw = 1;
        $limiter->recordFailure(self::IP, self::NOW + 1);
        $this->assertSame(1, $this->cleanups);
        $this->assertSame([$limiter->hash(self::IP)], array_keys($this->rows));
    }

    public function testADatabaseErrorNeverBlocksAndNeverThrows()
    {
        $limiter = $this->limiter();
        $this->databaseDown = true;

        $limiter->recordFailure(self::IP, self::NOW);
        $limiter->reset(self::IP);
        $this->assertFalse($limiter->isBlocked(self::IP, self::NOW));

        $this->assertCount(3, $this->logged);
        foreach ($this->logged as $message) {
            $this->assertStringNotContainsString(self::IP, $message, 'the IP is never logged');
        }
    }
}
