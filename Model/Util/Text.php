<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Util;

/**
 * HTML to plain text, for the product feed and the knowledge base body.
 */
class Text
{
    /**
     * Plain text of an HTML fragment
     *
     * Block closings and line breaks become new lines BEFORE the tags are removed, so paragraphs and list items
     * do not run into each other. Entities are decoded and white space is collapsed; single new lines are kept.
     *
     * @param string|null $html
     * @return string
     */
    public function plain(?string $html): string
    {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }

        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace(
            '#</(p|div|li|h[1-6]|tr|blockquote|section|article|ul|ol|table|pre)>#i',
            "\n",
            $html
        );

        $text = strip_tags($html);
        // the result is plain text sent to an API as JSON, never written into a page
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xC2\xA0", "\r"], [' ', ''], $text);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = (string) preg_replace("/\n{2,}/", "\n", $text);

        return trim($text);
    }

    /**
     * Plain text on a single line, for names and titles
     *
     * @param string|null $html
     * @return string
     */
    public function line(?string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->plain($html)));
    }

    /**
     * Length in characters, not in bytes
     *
     * @param string $text
     * @return int
     */
    public function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }
}
