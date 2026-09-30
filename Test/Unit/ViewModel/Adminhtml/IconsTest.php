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

use Ovebot\Chat\ViewModel\Adminhtml\Icons;
use Ovebot\Chat\ViewModel\Adminhtml\LinkText;
use PHPUnit\Framework\TestCase;

class IconsTest extends TestCase
{
    public function testEveryIconTheTemplatesNeedExists()
    {
        $needed = [
            'check', 'chat', 'settings', 'open_in_new', 'tune', 'shopping_cart', 'menu_book', 'rss_feed',
            'local_shipping', 'brush', 'expand_more', 'expand_less', 'chevron_left', 'refresh',
        ];

        $this->assertSame([], array_diff($needed, (new Icons())->getNames()));
    }

    public function testIconIsWellFormedSvg()
    {
        $icons = new Icons();

        foreach ($icons->getNames() as $name) {
            $svg = $icons->get($name);
            $document = new \DOMDocument();

            $this->assertTrue($document->loadXML($svg), $name);
            $this->assertSame('svg', $document->documentElement->nodeName);
            $this->assertSame('ovebotai-icon', $document->documentElement->getAttribute('class'));
            // path data holds only commands, numbers and separators: nothing that could close the attribute
            $this->assertMatchesRegularExpression('/^[MmLlHhVvCcSsAaZz0-9 .,-]+$/', $icons->getPaths()[$name], $name);
        }
    }

    public function testExtraClassIsCleaned()
    {
        $icons = new Icons();

        $this->assertStringContainsString(
            'class="ovebotai-icon ovebotai-ext-icon"',
            $icons->get('check', 'ovebotai-ext-icon')
        );
        // quotes, brackets and the equal sign are dropped, so the class attribute cannot be closed
        $this->assertStringContainsString(
            '<svg class="ovebotai-icon x onloadalert1" xmlns=',
            $icons->get('check', 'x" onload="alert(1)')
        );
        $this->assertSame('', $icons->get('no_such_icon'));
    }

    public function testLinkTextSplit()
    {
        $linkText = new LinkText();

        $this->assertSame(['Set it up in your ', '.'], $linkText->split('Set it up in your {link}.'));
        $this->assertSame(['', ' first'], $linkText->split('{link} first'));
        $this->assertSame(['No link here', ''], $linkText->split('No link here'));
    }
}
