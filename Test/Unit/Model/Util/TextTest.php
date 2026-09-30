<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Util;

use Ovebot\Chat\Model\Util\Text;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase
{
    /**
     * @dataProvider plainTexts
     */
    public function testPlain(?string $html, string $expected)
    {
        $this->assertSame($expected, (new Text())->plain($html));
    }

    public static function plainTexts(): array
    {
        return [
            'paragraphs and list items on their own lines' => [
                '<p>Hello <b>world</b></p><p>Second &amp;amp; more</p><ul><li>Item</li></ul>',
                "Hello world\nSecond &amp; more\nItem",
            ],
            'non-breaking spaces and entities' => ['A&nbsp;B &ndash;&nbsp;c', 'A B – c'],
            'script and style are dropped with their content' => [
                '<script>alert(1)</script>Visible<style>p{}</style>',
                'Visible',
            ],
            'script that holds a closing tag' => ['<script>var a = "</p>";</script><p>Text</p>', 'Text'],
            'line breaks' => ['one<br>two<br />three<BR/>four', "one\ntwo\nthree\nfour"],
            'headings and table rows' => [
                '<h2>Title</h2><table><tr><td>a</td><td>b</td></tr><tr><td>c</td></tr></table>',
                "Title\nab\nc",
            ],
            'empty lines and spaces are collapsed' => ["<div>  a \t b  </div>\r\n\r\n\n<div> c </div>", "a b\nc"],
            'romanian letters are kept' => ['<p>Livrare &icirc;n 24h, țară</p>', 'Livrare în 24h, țară'],
            'markup only' => ['<p></p><div><br></div>', ''],
            'empty' => ['', ''],
            'null' => [null, ''],
        ];
    }

    /**
     * @dataProvider lines
     */
    public function testLine(?string $html, string $expected)
    {
        $this->assertSame($expected, (new Text())->line($html));
    }

    public static function lines(): array
    {
        return [
            'new lines become spaces' => ["a<br>b\n\nc", 'a b c'],
            'title with markup' => ['  <span>About</span>  <i>us</i> ', 'About us'],
            'null' => [null, ''],
        ];
    }

    public function testLengthCountsCharactersNotBytes()
    {
        $text = new Text();

        $this->assertSame(5, $text->length('țărăn'));
        $this->assertSame(0, $text->length(''));
    }
}
