<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Widget;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\ResourceModel\Connection as ConnectionResource;
use Ovebot\Chat\Model\Widget\OptionsBuilder;
use Ovebot\Chat\Model\Widget\Settings;
use PHPUnit\Framework\TestCase;

class OptionsBuilderTest extends TestCase
{
    private function connection(): Connection
    {
        return new Connection(
            $this->createMock(Context::class),
            $this->createMock(Registry::class),
            $this->createMock(EncryptorInterface::class),
            new Json(),
            $this->createMock(ConnectionResource::class)
        );
    }

    private function live(): Connection
    {
        return $this->connection()->setWorkspace('my-shop')->setSetupComplete(true)->setChatEnabled(true);
    }

    public function testShownOnlyWithChatSetupAndWorkspace()
    {
        $builder = new OptionsBuilder();

        $this->assertTrue($builder->isActive($this->live()));
        $this->assertFalse($builder->isActive($this->live()->setChatEnabled(false)));
        $this->assertFalse($builder->isActive($this->live()->setSetupComplete(false)));
        $this->assertFalse($builder->isActive($this->live()->setWorkspace('')));
        // not a slug: it would end up in the host of a script
        $this->assertFalse($builder->isActive($this->live()->setWorkspace('evil.com/x')));
        $this->assertFalse($builder->isActive($this->connection()));
    }

    public function testLoaderBase()
    {
        $builder = new OptionsBuilder();

        $this->assertSame('https://my-shop.ovebot.ai/widget/', $builder->getLoaderBase($this->live()));
        $this->assertSame('', $builder->getLoaderBase($this->connection()));
    }

    public function testEmptyValuesAreLeftToTheChatLoader()
    {
        $connection = $this->live()->setWidget((new Settings())->defaults());

        $this->assertSame([], (new OptionsBuilder())->build($connection));
    }

    public function testEveryKeyWithItsType()
    {
        $widget = (new Settings())->sanitize([
            'accent_color' => '#0a1b2c',
            'theme' => 'dark',
            'language' => 'ro',
            'audio_beep' => 'none',
            'side' => 'left',
            'offset_y' => '40',
            'offset_x' => '0',
            'z_index' => '9000',
            'subtitle' => 'Usually replies in minutes',
            'proactive_message' => 'Need help?',
            'proactive_delay' => '12',
        ]);

        $options = (new OptionsBuilder())->build($this->live()->setWidget($widget));

        $this->assertSame(
            [
                'subtitle' => 'Usually replies in minutes',
                'accent_color' => '#0A1B2C',
                'proactive_message' => 'Need help?',
                'theme' => 'dark',
                'language' => 'ro',
                'audio_beep' => 'none',
                'side' => 'left',
                'proactive_delay' => 12,
                'offset_y' => 40,
                // zero is a number, so it is passed on
                'offset_x' => 0,
                'z_index' => 9000,
            ],
            $options
        );
    }

    public function testUnknownKeysAndValuesThatAreNotNumbersAreLeftOut()
    {
        $connection = $this->live()->setWidget([
            'offset_y' => 'abc',
            'z_index' => '',
            'theme' => ['dark'],
            'width' => '400',
            'auto_open' => 'true',
        ]);

        $this->assertSame([], (new OptionsBuilder())->build($connection));
    }

    /**
     * @return array
     */
    public static function agents(): array
    {
        return [
            'named agent' => ['agent-2', ['agent' => 'agent-2']],
            'default agent' => ['', []],
            'default agent by name' => ['default', []],
        ];
    }

    /**
     * @dataProvider agents
     * @param string $agent
     * @param array $expected
     */
    public function testOnlyANamedAgentIsSent(string $agent, array $expected)
    {
        $this->assertSame($expected, (new OptionsBuilder())->build($this->live()->setAgent($agent)));
    }

    public function testStateFollowsWhatTheStorefrontShows()
    {
        $builder = new OptionsBuilder();
        $connection = $this->live()->setWidget(['theme' => 'dark']);
        $state = $builder->getState($connection);

        $this->assertNotSame('', $state);
        $this->assertSame($state, $builder->getState($connection->setOrderEnabled(false)->setKbPageIds([1])));
        $this->assertSame($state, $builder->getState($connection->setTokens('AT', 'RT', 100)));

        $this->assertNotSame($state, $builder->getState($connection->setWidget(['theme' => 'light'])));
        $this->assertNotSame($state, $builder->getState($this->live()->setWidget(['theme' => 'dark'])->setAgent('a')));
        $this->assertNotSame(
            $state,
            $builder->getState($this->live()->setWidget(['theme' => 'dark'])->setWorkspace('other'))
        );
    }

    public function testStateOfAWidgetThatIsOffIsEmpty()
    {
        $builder = new OptionsBuilder();

        $this->assertSame('', $builder->getState($this->live()->setChatEnabled(false)->setWidget(['theme' => 'dark'])));
        $this->assertSame('', $builder->getState($this->live()->setSetupComplete(false)));
        $this->assertSame('', $builder->getState($this->connection()));
    }
}
