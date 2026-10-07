<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\ViewModel;

use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\Storefront\PurchaseProvider;
use Ovebot\Chat\Model\Widget\OptionsBuilder;
use Ovebot\Chat\ViewModel\Purchase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PurchaseTest extends TestCase
{
    /**
     * @var bool
     */
    private $active = true;

    /**
     * @var array|\Exception
     */
    private $purchases = [];

    /**
     * @var int
     */
    private $asked = 0;

    /**
     * @var string
     */
    private $agent = '';

    protected function setUp(): void
    {
        $this->active = true;
        $this->purchases = [['transaction_id' => 41, 'total' => 199.9, 'currency' => 'RON']];
        $this->asked = 0;
        $this->agent = '';
    }

    private function viewModel(): Purchase
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getAgent')->willReturnCallback(function () {
            return $this->agent;
        });

        $connections = $this->createMock(ConnectionRepository::class);
        $connections->method('get')->willReturn($connection);

        $options = $this->createMock(OptionsBuilder::class);
        $options->method('isActive')->willReturnCallback(function () {
            return $this->active;
        });

        $provider = $this->createMock(PurchaseProvider::class);
        $provider->method('getPurchases')->willReturnCallback(function () {
            $this->asked++;
            if ($this->purchases instanceof \Exception) {
                throw $this->purchases;
            }

            return $this->purchases;
        });

        return new Purchase($connections, $options, $provider, $this->createMock(LoggerInterface::class));
    }

    public function testPurchasesAsJson()
    {
        $this->assertSame(
            '[{"transaction_id":41,"total":199.9,"currency":"RON"}]',
            $this->viewModel()->getPurchasesJson()
        );
    }

    public function testNamesTheAgent()
    {
        $this->agent = 'eHAjWWvAVai6SeAA';

        $this->assertSame(
            '[{"agent":"eHAjWWvAVai6SeAA","transaction_id":41,"total":199.9,"currency":"RON"}]',
            $this->viewModel()->getPurchasesJson()
        );
    }

    public function testTheDefaultAgentIsNotNamed()
    {
        $this->agent = 'default';

        $this->assertSame(
            '[{"transaction_id":41,"total":199.9,"currency":"RON"}]',
            $this->viewModel()->getPurchasesJson()
        );
    }

    public function testNothingWhileTheWidgetIsOff()
    {
        $this->active = false;

        $this->assertSame('', $this->viewModel()->getPurchasesJson());
        // not even marked as reported: they would be lost for good
        $this->assertSame(0, $this->asked);
    }

    public function testNothingToReport()
    {
        $this->purchases = [];

        $this->assertSame('', $this->viewModel()->getPurchasesJson());
    }

    public function testAFailureNeverReachesThePage()
    {
        $this->purchases = new \RuntimeException('database is down');

        $this->assertSame('', $this->viewModel()->getPurchasesJson());
    }
}
