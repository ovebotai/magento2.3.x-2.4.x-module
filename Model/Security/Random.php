<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Security;

/**
 * Cryptographically strong random values.
 */
class Random
{
    /**
     * Random bytes
     *
     * @param int $length number of raw bytes
     * @return string binary string of exactly $length bytes
     * @throws \Exception when the system has no source of randomness
     */
    public function bytes(int $length): string
    {
        return random_bytes(max(1, $length));
    }

    /**
     * Lower case hex token of 2 * $bytes characters (16 bytes give 32 characters)
     *
     * @param int $bytes
     * @return string
     * @throws \Exception when the system has no source of randomness
     */
    public function hex(int $bytes): string
    {
        return bin2hex($this->bytes($bytes));
    }

    /**
     * Random whole number between $min and $max, both included
     *
     * @param int $min
     * @param int $max
     * @return int
     * @throws \Exception when the system has no source of randomness
     */
    public function number(int $min, int $max): int
    {
        return random_int(min($min, $max), max($min, $max));
    }
}
