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

use Ovebot\Chat\Model\Widget\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    private const KEYS = [
        'accent_color',
        'theme',
        'language',
        'audio_beep',
        'side',
        'offset_y',
        'offset_x',
        'z_index',
        'subtitle',
        'proactive_message',
        'proactive_delay',
    ];

    /**
     * @param string $key
     * @param mixed $value
     * @return string
     */
    private function clean(string $key, $value): string
    {
        return (new Settings())->sanitize([$key => $value])[$key];
    }

    public function testElevenKeysAllEmptyByDefault()
    {
        $settings = new Settings();

        $this->assertSame(self::KEYS, array_keys($settings->defaults()));
        $this->assertSame(array_fill_keys(self::KEYS, ''), $settings->defaults());
        $this->assertSame($settings->defaults(), $settings->sanitize([]));
    }

    public function testEveryKeyIsPassedOnAsTextOrAsNumber()
    {
        $passedOn = array_merge(Settings::STRING_KEYS, Settings::INT_KEYS);
        sort($passedOn);
        $keys = self::KEYS;
        sort($keys);

        $this->assertSame($keys, $passedOn);
    }

    public function testValidValuesAreKept()
    {
        $values = [
            'accent_color' => '#0A1B2C',
            'theme' => 'dark',
            'language' => 'es',
            'audio_beep' => 'none',
            'side' => 'left',
            'offset_y' => '40',
            'offset_x' => '15',
            'z_index' => '2147483644',
            'subtitle' => 'Usually replies in a few minutes',
            'proactive_message' => 'Need help finding something?',
            'proactive_delay' => '12',
        ];

        $this->assertSame($values, (new Settings())->sanitize($values));
    }

    public function testUnknownKeysAreDropped()
    {
        $clean = (new Settings())->sanitize(['theme' => 'light', 'workspace' => 'other', 'chat_status' => '1']);

        $this->assertSame(self::KEYS, array_keys($clean));
        $this->assertSame('light', $clean['theme']);
    }

    /**
     * @dataProvider listValues
     * @param string $key
     * @param mixed $value
     * @param string $expected
     */
    public function testLists(string $key, $value, string $expected)
    {
        $this->assertSame($expected, $this->clean($key, $value));
    }

    public static function listValues(): array
    {
        return [
            'theme' => ['theme', 'dark', 'dark'],
            'theme in capitals' => ['theme', ' DARK ', 'dark'],
            'unknown theme' => ['theme', 'blue', ''],
            'every language' => ['language', 'es', 'es'],
            'browser language' => ['language', 'auto', 'auto'],
            'unknown language' => ['language', 'it', ''],
            'language with a line break' => ['language', "ro\nx", ''],
            'side' => ['side', 'left', 'left'],
            'unknown side' => ['side', 'top', ''],
            'list given as array' => ['side', ['left'], ''],
            'sound' => ['audio_beep', 'play', 'play'],
            'sound off' => ['audio_beep', 'none', 'none'],
            'sound as 1' => ['audio_beep', '1', 'play'],
            'sound as true' => ['audio_beep', 'true', 'play'],
            'sound as boolean true' => ['audio_beep', true, 'play'],
            'sound as 0' => ['audio_beep', '0', 'none'],
            'sound as false' => ['audio_beep', 'false', 'none'],
            'sound as boolean false' => ['audio_beep', false, 'none'],
            'unknown sound' => ['audio_beep', 'loud', ''],
        ];
    }

    /**
     * @dataProvider colors
     * @param string $value
     * @param string $expected
     */
    public function testColor(string $value, string $expected)
    {
        $this->assertSame($expected, $this->clean('accent_color', $value));
    }

    public static function colors(): array
    {
        return [
            'upper case' => ['#615ED6', '#615ED6'],
            'lower case' => ['#ff00aa', '#FF00AA'],
            'with spaces around' => ['  #ff00aa ', '#FF00AA'],
            'short form' => ['#fff', ''],
            'without the hash' => ['615ED6', ''],
            'not hex' => ['#61ZED6', ''],
            'name' => ['red', ''],
            'with alpha' => ['#615ED6FF', ''],
            'followed by markup' => ['#615ED6"><script>', ''],
            'followed by a line break' => ["#615ED6\n", '#615ED6'],
            'line break inside' => ["#615ED6\nx", ''],
        ];
    }

    /**
     * @dataProvider numbers
     * @param string $key
     * @param mixed $value
     * @param string $expected
     */
    public function testNumbers(string $key, $value, string $expected)
    {
        $this->assertSame($expected, $this->clean($key, $value));
    }

    public static function numbers(): array
    {
        return [
            'offset' => ['offset_y', '35', '35'],
            'offset as number' => ['offset_y', 35, '35'],
            'zero' => ['offset_x', '0', '0'],
            'leading zeros' => ['offset_x', '007', '7'],
            'offset above the limit' => ['offset_y', '10001', '10000'],
            'side offset above the limit' => ['offset_x', '99999', '10000'],
            'negative' => ['offset_y', '-5', ''],
            'decimal' => ['offset_y', '1.5', ''],
            'exponent' => ['offset_y', '1e3', ''],
            'text' => ['offset_y', 'abc', ''],
            'empty' => ['offset_y', '', ''],
            'stacking order' => ['z_index', '2147483644', '2147483644'],
            'stacking order at the limit' => ['z_index', '2147483647', '2147483647'],
            'stacking order above the limit' => ['z_index', '2147483648', '2147483647'],
            'stacking order far above the limit' => ['z_index', '99999999999999999999', '2147483647'],
            'delay' => ['proactive_delay', '4', '4'],
            'delay above the limit' => ['proactive_delay', '301', '300'],
        ];
    }

    /**
     * @dataProvider texts
     * @param string $value
     * @param string $expected
     */
    public function testText(string $value, string $expected)
    {
        $this->assertSame($expected, $this->clean('subtitle', $value));
        $this->assertSame($expected, $this->clean('proactive_message', $value));
    }

    public static function texts(): array
    {
        return [
            'plain' => ['Need help?', 'Need help?'],
            'with markup' => ['<b>Need</b> <script>alert(1)</script>help?', 'Need alert(1)help?'],
            'with line breaks' => ["Need\r\nhelp\tnow?", 'Need help now?'],
            'with spaces around' => ["  Need help? \n", 'Need help?'],
            'only markup' => ['<br>', ''],
            'diacritics' => ['Răspundem în câteva minute', 'Răspundem în câteva minute'],
        ];
    }

    public function testTextIsCutByCharactersNotByBytes()
    {
        $long = str_repeat('ă', 300);

        $clean = $this->clean('subtitle', $long);

        $this->assertSame(255, mb_strlen($clean));
        $this->assertSame(str_repeat('ă', 255), $clean);
    }

    public function testStoredSettingsOverTheDefaults()
    {
        $settings = new Settings();

        $widget = $settings->withDefaults([
            'theme' => 'dark',
            'offset_y' => 35,
            'subtitle' => ['not', 'text'],
            'other' => 'dropped',
        ]);

        $this->assertSame(self::KEYS, array_keys($widget));
        $this->assertSame('dark', $widget['theme']);
        $this->assertSame('35', $widget['offset_y']);
        $this->assertSame('', $widget['subtitle']);
        $this->assertSame($settings->defaults(), $settings->withDefaults([]));
    }

    public function testPlaceholdersAreTheDefaultsOfTheChatLoader()
    {
        $placeholders = (new Settings())->placeholders();

        $this->assertSame('#615ED6', $placeholders['accent_color']);
        $this->assertSame('20', $placeholders['offset_y']);
        $this->assertSame('20', $placeholders['offset_x']);
        $this->assertSame('2147483644', $placeholders['z_index']);
        $this->assertSame('4', $placeholders['proactive_delay']);
        // a placeholder is a value the form would accept
        $this->assertSame($placeholders, array_intersect_key((new Settings())->sanitize($placeholders), $placeholders));
    }
}
