<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The icons of the admin page, as inline SVG: no icon font and nothing loaded from outside.
 *
 * The shapes are the Material Icons of Google, used under the Apache License 2.0.
 */
class Icons implements ArgumentInterface
{
    private const PATHS = [
        'check' => 'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z',
        'chat' => 'M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9z'
            . 'm8 5H6v-2h8v2zm4-6H6V6h12v2z',
        'settings' => 'M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61'
            . 'l-1.92-3.32'
            . 'c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84'
            . 'c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87'
            . 'c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61'
            . 'l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84'
            . 'c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32'
            . 'c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6'
            . '-1.62 3.6-3.6 3.6z',
        'open_in_new' => 'M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59'
            . 'l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z',
        'tune' => 'M3 17v2h6v-2H3zM3 5v2h10V5H3zm10 16v-2h8v-2h-8v-2h-2v6h2zM7 9v2H3v2h4v2h2V9H7zm14 4v-2H11v2h10z'
            . 'm-6-4h2V7h4V5h-4V3h-2v6z',
        'shopping_cart' => 'M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45'
            . 'c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45'
            . 'c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16'
            . 'c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z',
        'menu_book' => 'M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 4h5v8l-2.5-1.5'
            . 'L6 12V4z',
        'rss_feed' => 'M6.18 15.64a2.18 2.18 0 1 0 0 4.36 2.18 2.18 0 0 0 0-4.36zM4 4.44v2.83'
            . 'c7.03 0 12.73 5.7 12.73 12.73h2.83c0-8.59-6.97-15.56-15.56-15.56zm0 5.66v2.83'
            . 'c3.9 0 7.07 3.17 7.07 7.07h2.83c0-5.47-4.43-9.9-9.9-9.9z',
        'local_shipping' => 'M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6'
            . 'c0 1.66 1.34 3 3 3s3-1.34 3-3'
            . 'h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9'
            . 'l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z',
        'brush' => 'M7 14c-1.66 0-3 1.34-3 3 0 1.31-1.16 2-2 2 .92 1.22 2.49 2 4 2 2.21 0 4-1.79 4-4 0-1.66-1.34-3-3-3z'
            . 'm13.71-9.37l-1.34-1.34c-.39-.39-1.02-.39-1.41 0L9 12.25 11.75 15l8.96-8.96c.39-.39.39-1.02 0-1.41z',
        'expand_more' => 'M16.59 8.59L12 13.17 7.41 8.59 6 10l6 6 6-6z',
        'expand_less' => 'M12 8l-6 6 1.41 1.41L12 10.83l4.59 4.58L18 14z',
        'chevron_left' => 'M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z',
        'refresh' => 'M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6'
            . 'h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4'
            . 'l-2.35 2.35z',
    ];

    /**
     * SVG markup of an icon; empty for an unknown name
     *
     * The markup is built only from the constants above, so templates print it without escaping.
     *
     * @param string $name
     * @param string $class extra CSS classes: letters, digits, dash, underscore and space
     * @return string
     */
    public function get(string $name, string $class = ''): string
    {
        if (!isset(self::PATHS[$name])) {
            return '';
        }

        $class = trim('ovebotai-icon ' . preg_replace('/[^A-Za-z0-9_ -]/', '', $class));

        return '<svg class="' . $class . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"'
            . ' aria-hidden="true" focusable="false"><path d="' . self::PATHS[$name] . '"/></svg>';
    }

    /**
     * Names of the icons
     *
     * @return string[]
     */
    public function getNames(): array
    {
        return array_keys(self::PATHS);
    }

    /**
     * Path data by icon name, for the scripts that build icons
     *
     * @return string[]
     */
    public function getPaths(): array
    {
        return self::PATHS;
    }
}
