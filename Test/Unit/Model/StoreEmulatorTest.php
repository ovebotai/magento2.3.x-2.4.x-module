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

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ovebot\Chat\Model\StoreContext;
use Ovebot\Chat\Model\StoreContext\StoreView;
use Ovebot\Chat\Model\StoreEmulator;
use PHPUnit\Framework\TestCase;

class StoreEmulatorTest extends TestCase
{
    /**
     * @var string[] what happened, in order
     */
    private $steps = [];

    protected function setUp(): void
    {
        $this->steps = [];
    }

    private function emulator(): StoreEmulator
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->method('startEnvironmentEmulation')->willReturnCallback(function ($storeId, $area, $force) {
            $this->steps[] = 'start ' . $storeId . ' ' . $area . ' ' . ($force ? 'forced' : 'not forced');
        });
        $emulation->method('stopEnvironmentEmulation')->willReturnCallback(function () {
            $this->steps[] = 'stop';
        });

        $appState = $this->createMock(State::class);
        $appState->method('emulateAreaCode')->willReturnCallback(function ($area, $callback) {
            $this->steps[] = 'area ' . $area;
            try {
                return $callback();
            } finally {
                $this->steps[] = 'area back';
            }
        });

        // the store view emulated is the default one, whatever the request came on
        $storeView = $this->createMock(StoreView::class);
        $storeView->method('getStoreId')->willReturn(5);
        $storeContext = $this->createMock(StoreContext::class);
        $storeContext->method('get')->willReturn($storeView);

        // store views 1 and 3 exist; any other id is a deleted one
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function ($id) {
            if (!in_array($id, [1, 3], true)) {
                throw new NoSuchEntityException(__('The store that was requested wasn\'t found.'));
            }
            $store = $this->createMock(Store::class);
            $store->method('getId')->willReturn((string) $id);

            return $store;
        });

        return new StoreEmulator($emulation, $appState, $storeContext, $storeManager);
    }

    public function testReturnsTheResultAndStopsTheEmulation()
    {
        $result = $this->emulator()->run(function () {
            $this->steps[] = 'callback';

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(
            [
                'start 5 ' . Area::AREA_FRONTEND . ' forced',
                'area ' . Area::AREA_FRONTEND,
                'callback',
                'area back',
                'stop',
            ],
            $this->steps
        );
    }

    public function testStopsTheEmulationWhenTheCallbackFails()
    {
        $error = $this->failure(function () {
            throw new \RuntimeException('feed failed');
        });

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame('feed failed', $error->getMessage());
        $this->assertSame(
            ['start 5 ' . Area::AREA_FRONTEND . ' forced', 'area ' . Area::AREA_FRONTEND, 'area back', 'stop'],
            $this->steps
        );
    }

    public function testRunsOnTheStoreViewOfAnOrder()
    {
        $result = $this->emulator()->runOn(3, function () {
            $this->steps[] = 'callback';

            return 'described';
        });

        $this->assertSame('described', $result);
        $this->assertSame(
            [
                'start 3 ' . Area::AREA_FRONTEND . ' forced',
                'area ' . Area::AREA_FRONTEND,
                'callback',
                'area back',
                'stop',
            ],
            $this->steps
        );
    }

    /**
     * @dataProvider missingStoreViews
     */
    public function testTheDefaultStoreViewStandsInForAMissingOne(?int $storeId)
    {
        $this->emulator()->runOn($storeId, function () {
            return null;
        });

        $this->assertSame('start 5 ' . Area::AREA_FRONTEND . ' forced', $this->steps[0]);
    }

    public static function missingStoreViews(): array
    {
        return [
            'deleted store view' => [9],
            'no store view' => [null],
            'admin' => [0],
        ];
    }

    /**
     * What the emulator lets out when the callback fails
     */
    private function failure(callable $callback): ?\Throwable
    {
        try {
            $this->emulator()->run($callback);
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }
}
