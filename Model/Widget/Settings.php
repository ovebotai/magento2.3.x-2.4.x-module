<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Widget;

/**
 * Validation and defaults of the widget appearance settings (Settings > Appearance).
 *
 * An empty value means "the default of the chat loader", and an empty key is not passed on to the storefront
 * widget. Everything here stays in the shop, except the language, which also goes to /setup.
 */
class Settings
{
    public const THEMES = ['', 'light', 'dark'];
    public const LANGUAGES = ['', 'auto', 'en', 'ro', 'de', 'fr', 'es'];
    public const AUDIO = ['', 'play', 'none'];
    public const SIDES = ['', 'right', 'left'];

    /**
     * Keys passed on to the chat loader as strings, when they are not empty
     */
    public const STRING_KEYS = [
        'subtitle',
        'accent_color',
        'proactive_message',
        'theme',
        'language',
        'audio_beep',
        'side',
    ];

    /**
     * Keys passed on to the chat loader as integers, when they are numbers
     */
    public const INT_KEYS = ['proactive_delay', 'offset_y', 'offset_x', 'z_index'];

    /**
     * What the chat loader uses for an empty value; shown in the form as placeholders
     */
    public const PLACEHOLDERS = [
        'accent_color' => '#615ED6',
        'offset_y' => '20',
        'offset_x' => '20',
        'z_index' => '2147483644',
        'proactive_delay' => '4',
    ];

    public const MAX_OFFSET = 10000;
    public const MAX_Z_INDEX = 2147483647;
    public const MAX_PROACTIVE_DELAY = 300;
    public const MAX_TEXT_LENGTH = 255;

    // \z, not $: in PHP "$" also matches before a trailing line break
    private const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}\z/';

    /**
     * Every widget key, empty
     *
     * @return string[]
     */
    public function defaults(): array
    {
        return [
            'accent_color' => '',
            'theme' => '',
            'language' => '',
            'audio_beep' => '',
            'side' => '',
            'offset_y' => '',
            'offset_x' => '',
            'z_index' => '',
            'subtitle' => '',
            'proactive_message' => '',
            'proactive_delay' => '',
        ];
    }

    /**
     * Placeholders of the form: the defaults of the chat loader
     *
     * @return string[]
     */
    public function placeholders(): array
    {
        return self::PLACEHOLDERS;
    }

    /**
     * Clean the values of the form
     *
     * Lists accept only their own values, the colour must be #RRGGBB, numbers are kept between their limits and
     * texts lose their markup. Unknown keys are dropped; missing keys become empty.
     *
     * @param array $raw key => value, as it came from the request
     * @return string[]
     */
    public function sanitize(array $raw): array
    {
        return [
            'accent_color' => $this->color($this->value($raw, 'accent_color')),
            'theme' => $this->option($this->value($raw, 'theme'), self::THEMES),
            'language' => $this->option($this->value($raw, 'language'), self::LANGUAGES),
            'audio_beep' => $this->audio($this->value($raw, 'audio_beep')),
            'side' => $this->option($this->value($raw, 'side'), self::SIDES),
            'offset_y' => $this->number($this->value($raw, 'offset_y'), 0, self::MAX_OFFSET),
            'offset_x' => $this->number($this->value($raw, 'offset_x'), 0, self::MAX_OFFSET),
            'z_index' => $this->number($this->value($raw, 'z_index'), 0, self::MAX_Z_INDEX),
            'subtitle' => $this->text($this->value($raw, 'subtitle')),
            'proactive_message' => $this->text($this->value($raw, 'proactive_message')),
            'proactive_delay' => $this->number($this->value($raw, 'proactive_delay'), 0, self::MAX_PROACTIVE_DELAY),
        ];
    }

    /**
     * The stored settings over the defaults, for the form
     *
     * @param array $stored
     * @return string[]
     */
    public function withDefaults(array $stored): array
    {
        $widget = $this->defaults();
        foreach (array_keys($widget) as $key) {
            if (isset($stored[$key]) && is_scalar($stored[$key])) {
                $widget[$key] = (string) $stored[$key];
            }
        }

        return $widget;
    }

    /**
     * Read a value as trimmed text
     *
     * @param array $raw
     * @param string $key
     * @return string
     */
    private function value(array $raw, string $key): string
    {
        if (!isset($raw[$key]) || !is_scalar($raw[$key])) {
            return '';
        }
        if (is_bool($raw[$key])) {
            return $raw[$key] ? 'true' : 'false';
        }

        return trim((string) $raw[$key]);
    }

    /**
     * One of the allowed values; empty for anything else
     *
     * @param string $value
     * @param string[] $allowed
     * @return string
     */
    private function option(string $value, array $allowed): string
    {
        $value = strtolower($value);

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * The sound setting; another platform kept it as a boolean, so 1/true and 0/false are read too
     *
     * @param string $value
     * @return string
     */
    private function audio(string $value): string
    {
        $value = strtolower($value);
        if ($value === '1' || $value === 'true') {
            return 'play';
        }
        if ($value === '0' || $value === 'false') {
            return 'none';
        }

        return $this->option($value, self::AUDIO);
    }

    /**
     * A colour as #RRGGBB, in upper case; empty for anything else
     *
     * @param string $value
     * @return string
     */
    private function color(string $value): string
    {
        return preg_match(self::COLOR_PATTERN, $value) ? strtoupper($value) : '';
    }

    /**
     * A whole number kept between its limits, as text; empty when the value is not made of digits only
     *
     * @param string $value
     * @param int $min
     * @param int $max
     * @return string
     */
    private function number(string $value, int $min, int $max): string
    {
        if ($value === '' || !ctype_digit($value)) {
            return '';
        }

        // a longer number is above every limit, and would not fit an integer on a 32 bit system
        if (strlen(ltrim($value, '0')) > 10) {
            return (string) $max;
        }

        return (string) (int) max($min, min($max, (float) $value));
    }

    /**
     * One line of plain text, of limited length
     *
     * @param string $value
     * @return string
     */
    private function text(string $value): string
    {
        $value = trim(strip_tags($value));
        $value = trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));

        return mb_substr($value, 0, self::MAX_TEXT_LENGTH);
    }
}
