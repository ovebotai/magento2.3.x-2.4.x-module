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

use Ovebot\Chat\Model\Feed\ProductCounter;
use Ovebot\Chat\Model\Feed\ProductSelection;
use Ovebot\Chat\Model\StoreContext;
use Ovebot\Chat\Model\StoreContext\StoreView;
use Ovebot\Chat\Model\StoreEmulator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductCounterTest extends TestCase
{
    /**
     * @var string[] what happened, in order
     */
    private $steps = [];

    /**
     * @var string[]
     */
    private $logged = [];

    /**
     * @var bool
     */
    private $fails = false;

    protected function setUp(): void
    {
        $this->steps = [];
        $this->logged = [];
        $this->fails = false;
    }

    private function counter(): ProductCounter
    {
        $selection = $this->createMock(ProductSelection::class);
        $selection->method('countEnabled')->willReturnCallback(function () {
            $this->steps[] = 'count enabled';

            return 40;
        });
        $selection->method('countItems')->willReturnCallback(function ($context, $batchSize) {
            $this->steps[] = 'count items';
            if ($this->fails) {
                throw new \RuntimeException('price index is missing');
            }

            return 57;
        });

        $storeContext = $this->createMock(StoreContext::class);
        $storeContext->method('get')->willReturn($this->createMock(StoreView::class));

        $emulator = $this->createMock(StoreEmulator::class);
        $emulator->method('run')->willReturnCallback(function (callable $callback) {
            $this->steps[] = 'emulation starts';
            try {
                return $callback();
            } finally {
                $this->steps[] = 'emulation stops';
            }
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->logged[] = $message;
        });

        return new ProductCounter($selection, $storeContext, $emulator, $logger);
    }

    public function testCountsUnderTheEmulationOfTheStorefront()
    {
        $this->assertSame(['total' => 40, 'feed_count' => 57], $this->counter()->counts());
        $this->assertSame(
            ['emulation starts', 'count enabled', 'count items', 'emulation stops'],
            $this->steps
        );
    }

    public function testCountsOncePerRequest()
    {
        $counter = $this->counter();
        $counter->counts();
        $counter->counts();

        $this->assertCount(4, $this->steps);
    }

    public function testFailureGivesNullAndIsLogged()
    {
        $this->fails = true;
        $counter = $this->counter();

        $this->assertNull($counter->counts());
        $this->assertNull($counter->counts());
        $this->assertSame(['Products could not be counted: price index is missing'], $this->logged);
    }
}
