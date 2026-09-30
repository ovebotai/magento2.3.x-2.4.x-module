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
 * Helps templates put a link inside a translated sentence without printing translated HTML.
 *
 * The sentence is translated with a marker where the link goes; the template escapes the text before and
 * after it and writes the link itself.
 */
class LinkText implements ArgumentInterface
{
    public const MARKER = '{link}';

    /**
     * Split a sentence at the link marker
     *
     * @param string $text for example (string) __('Set it up in your %1.', LinkText::MARKER)
     * @return string[] the text before the link and the text after it
     */
    public function split(string $text): array
    {
        $parts = explode(self::MARKER, $text, 2);

        return [$parts[0], isset($parts[1]) ? $parts[1] : ''];
    }
}
