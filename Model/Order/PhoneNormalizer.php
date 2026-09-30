<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Order;

/**
 * Reduces a phone number to its last 9 digits, the part that stays the same whatever prefix the customer used.
 */
class PhoneNormalizer
{
    /**
     * Digits compared
     */
    public const LENGTH = 9;

    /**
     * Last 9 digits: "+40 721-234.567", "0721234567" and "0040721234567" all give "721234567"
     *
     * @param mixed $phone
     * @return string '' when fewer than 9 digits remain
     */
    public function core($phone): string
    {
        $digits = (string) preg_replace('/\D/', '', is_scalar($phone) ? (string) $phone : '');
        $digits = ltrim($digits, '0');

        return strlen($digits) >= self::LENGTH ? substr($digits, -self::LENGTH) : '';
    }
}
