<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\ViewModel\Adminhtml;

use Magento\Backend\Model\UrlInterface;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\IntegrationFactory;
use Ovebot\Chat\ViewModel\Adminhtml\Dashboard;
use PHPUnit\Framework\TestCase;

class DashboardTest extends TestCase
{
    /**
     * @var int calls of Integration::getKbEntries()
     */
    private $kbReads = 0;

    /**
     * @param array $knowledgeBase the answer of Integration::getKbEntries()
     * @param int $products
     * @param string $workspace
     * @return Dashboard
     */
    private function dashboard(array $knowledgeBase = [], int $products = 0, string $workspace = 'my-shop'): Dashboard
    {
        $this->kbReads = 0;

        $integration = $this->createMock(Integration::class);
        $integration->method('getKbEntries')->willReturnCallback(function () use ($knowledgeBase) {
            $this->kbReads++;

            return $knowledgeBase;
        });
        $integration->method('getIndexedProductCount')->willReturn($products);
        $integration->method('getAccountUrl')->willReturnCallback(function ($path = '') use ($workspace) {
            return $workspace !== '' ? 'https://' . $workspace . '.ovebot.ai' . $path : '';
        });

        $factory = $this->createMock(IntegrationFactory::class);
        $factory->method('create')->willReturn($integration);

        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(function ($route, $params = []) {
            return 'admin|' . $route . '|' . json_encode($params);
        });

        return new Dashboard($factory, $url);
    }

    public function testKnowledgeBaseIsReadOnce()
    {
        $dashboard = $this->dashboard([
            'entries' => [
                ['id' => 1, 'title' => 'About', 'is_active' => true, 'edit_url' => 'u1'],
                ['id' => 2, 'title' => 'Delivery', 'is_active' => false, 'edit_url' => 'u2'],
                ['id' => 3, 'title' => 'Returns', 'is_active' => true, 'edit_url' => 'u3'],
            ],
            'error' => false,
        ]);

        $this->assertFalse($dashboard->hasKbError());
        $this->assertCount(3, $dashboard->getKbEntries());
        $this->assertSame('2 of 3 entries active', $dashboard->getKbSummary());
        $this->assertSame(1, $this->kbReads);
    }

    public function testKnowledgeBaseThatCouldNotBeRead()
    {
        $dashboard = $this->dashboard(['entries' => [], 'error' => true]);

        $this->assertTrue($dashboard->hasKbError());
        $this->assertSame([], $dashboard->getKbEntries());
    }

    public function testAnswerWithoutTheExpectedKeys()
    {
        $dashboard = $this->dashboard([]);

        $this->assertFalse($dashboard->hasKbError());
        $this->assertSame([], $dashboard->getKbEntries());
        $this->assertSame('0 of 0 entries active', $dashboard->getKbSummary());
    }

    public function testProductCount()
    {
        $dashboard = $this->dashboard([], 40377);

        $this->assertSame(40377, $dashboard->getProductsCount());
        $this->assertSame('40,377', $dashboard->getProductsCountLabel());
        $this->assertSame('0', $this->dashboard()->getProductsCountLabel());
    }

    public function testAccountLinks()
    {
        $dashboard = $this->dashboard();

        $this->assertSame('https://my-shop.ovebot.ai', $dashboard->getAccountUrl());
        $this->assertSame('https://my-shop.ovebot.ai/products', $dashboard->getProductsUrl());
        $this->assertSame('https://my-shop.ovebot.ai/knowledge-base/create', $dashboard->getKbCreateUrl());
    }

    public function testAccountLinksWithoutWorkspace()
    {
        $dashboard = $this->dashboard([], 0, '');

        $this->assertSame('', $dashboard->getAccountUrl());
        $this->assertSame('', $dashboard->getProductsUrl());
        $this->assertSame('', $dashboard->getKbCreateUrl());
    }

    public function testSettingsLinks()
    {
        $dashboard = $this->dashboard();

        $this->assertSame(
            'admin|ovebot_chat/dashboard/index|{"view":"settings"}',
            $dashboard->getSettingsUrl()
        );
        $this->assertSame(
            'admin|ovebot_chat/dashboard/index|{"view":"settings","highlight":"oveOrderEnabled"}',
            $dashboard->getSettingsUrl(Dashboard::FIELD_ORDERS)
        );
    }
}
