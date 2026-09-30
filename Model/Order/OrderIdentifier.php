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
 * Turns the order number a customer typed into the values the lookup can match.
 *
 * The customer knows the order number (increment_id, "000000123"); an operator or another integration may send
 * the internal id (entity_id). Both are returned as CANDIDATES and the lookup matches any of them, instead of
 * guessing one format and missing the order when the guess is wrong. The lookup checks the email or the phone
 * as well, so a candidate never gives away an order of someone else.
 */
class OrderIdentifier
{
    /**
     * Length of sales_order.increment_id
     */
    private const MAX_LENGTH = 50;

    /**
     * Digits of Magento's default order numbers ("000000123")
     */
    private const PADDED_LENGTH = 9;

    // \z, not $: in PHP "$" also matches before a trailing line break
    private const INCREMENT_PATTERN = '/^[A-Za-z0-9_-]+\z/';

    private const DIGITS_PATTERN = '/^[0-9]+\z/';

    /**
     * Candidates for the order number and the order id
     *
     * "#000000123" gives the number "000000123" and the id 123. A number shorter than Magento's default order
     * numbers is also tried padded with zeros: "123" gives "123", "000000123" and the id 123. Text that cannot be
     * an order number ("comanda nr. 45") gives only the id made of its digits.
     *
     * @param mixed $raw
     * @return array|null ['increment_ids' => string[], 'entity_id' => int|null]; null when nothing can be matched
     */
    public function parse($raw): ?array
    {
        $raw = is_scalar($raw) ? trim((string) $raw) : '';
        // "#123" / "# 123": the hash is how people write order numbers
        $clean = trim(ltrim($raw, '#'));
        if ($clean === '') {
            return null;
        }

        $incrementIds = [];
        $entityId = null;
        if (strlen($clean) <= self::MAX_LENGTH && preg_match(self::INCREMENT_PATTERN, $clean)) {
            $incrementIds[] = $clean;
        }

        if (preg_match(self::DIGITS_PATTERN, $clean)) {
            if (strlen($clean) < self::PADDED_LENGTH) {
                $incrementIds[] = str_pad($clean, self::PADDED_LENGTH, '0', STR_PAD_LEFT);
            }
            $entityId = $this->toId($clean);
        } elseif (!$incrementIds) {
            // "comanda nr. 45": digits are looked for only when the text cannot be an order number, otherwise
            // "ORD-12" would also become the id 12
            $entityId = $this->toId((string) preg_replace('/\D/', '', $clean));
        }

        if (!$incrementIds && $entityId === null) {
            return null;
        }

        return ['increment_ids' => $incrementIds, 'entity_id' => $entityId];
    }

    /**
     * A positive order id from digits; null for zero, nothing, or a number too large for the column
     *
     * @param string $digits
     * @return int|null
     */
    private function toId(string $digits): ?int
    {
        $digits = ltrim($digits, '0');
        // sales_order.entity_id is an unsigned int
        if ($digits === '' || strlen($digits) > 10 || (int) $digits > 4294967295) {
            return null;
        }

        return (int) $digits;
    }
}
