<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Block;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Ovebot\Chat\Block\Widget;
use Ovebot\Chat\Model\Cache\WidgetCache;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\ResourceModel\Connection as ConnectionResource;
use Ovebot\Chat\Model\Widget\OptionsBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WidgetTest extends TestCase
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var bool
     */
    private $readFails = false;

    /**
     * @var int
     */
    private $reads = 0;

    /**
     * @var string[]
     */
    private $logged = [];

    protected function setUp(): void
    {
        $this->connection = new Connection(
            $this->createMock(ModelContext::class),
            $this->createMock(Registry::class),
            $this->createMock(EncryptorInterface::class),
            new Json(),
            $this->createMock(ConnectionResource::class)
        );
        $this->connection->setWorkspace('my-shop')->setSetupComplete(true)->setChatEnabled(true);
        $this->readFails = false;
        $this->reads = 0;
        $this->logged = [];
    }

    private function block(): Widget
    {
        $objectManager = new ObjectManager($this);

        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(function ($route, $params) {
            return 'https://www.shop-test.ro/en/' . $route . '/?' . http_build_query($params);
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->logged[] = (string) $message;
        });
        $context = $objectManager->getObject(Context::class, ['urlBuilder' => $url, 'logger' => $logger]);

        $connections = $this->createMock(ConnectionRepository::class);
        $connections->method('get')->willReturnCallback(function () {
            $this->reads++;
            if ($this->readFails) {
                throw new \RuntimeException('database is down');
            }

            return $this->connection;
        });

        return $objectManager->getObject(Widget::class, [
            'context' => $context,
            'connections' => $connections,
            'options' => new OptionsBuilder(),
        ]);
    }

    public function testEveryPageGetsTheTagOfTheWidget()
    {
        $this->assertSame([WidgetCache::TAG], $this->block()->getIdentities());

        // also while the widget is off: switching it on must reach the pages cached without it
        $this->connection->setChatEnabled(false);
        $this->assertSame([WidgetCache::TAG], $this->block()->getIdentities());
    }

    public function testActiveWithTheSameConditionAsTheOptions()
    {
        $this->assertTrue($this->block()->isActive());

        $this->connection->setSetupComplete(false);
        $this->assertFalse($this->block()->isActive());
    }

    public function testConfigurationOfTheScript()
    {
        $this->connection->setAgent('agent-2')->setWidget(['side' => 'left', 'offset_y' => '40']);

        $config = json_decode($this->block()->getConfigJson(), true);

        $this->assertSame('https://my-shop.ovebot.ai/widget/', $config['base']);
        $this->assertSame(['side' => 'left', 'offset_y' => 40, 'agent' => 'agent-2'], $config['chat']);
        $this->assertSame('https://www.shop-test.ro/en/ovebot/preview/validate/?_nosid=1', $config['previewUrl']);
    }

    public function testNoOptionsGiveAnObject()
    {
        $json = $this->block()->getConfigJson();

        $this->assertStringContainsString('"chat":{}', $json);
    }

    public function testTheJsonIsSafeInsideTheMarkup()
    {
        $this->connection->setWidget([
            'subtitle' => '</div><script>alert("x")</script>',
            'proactive_message' => "it's",
        ]);

        $json = $this->block()->getConfigJson();

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString("'", $json);
        $this->assertSame(
            '</div><script>alert("x")</script>',
            json_decode($json, true)['chat']['subtitle']
        );
    }

    public function testTheConnectionIsReadOnce()
    {
        $block = $this->block();
        $block->isActive();
        $block->getConfigJson();
        $block->isActive();

        $this->assertSame(1, $this->reads);
    }

    public function testAConnectionThatCannotBeReadHidesTheWidget()
    {
        $this->readFails = true;
        $block = $this->block();

        $this->assertFalse($block->isActive());
        $this->assertSame('{}', $block->getConfigJson());
        $this->assertSame(['Ovebot chat widget left out (RuntimeException).'], $this->logged);
    }
}
