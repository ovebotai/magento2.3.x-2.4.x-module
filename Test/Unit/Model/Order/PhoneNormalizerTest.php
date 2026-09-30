<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Order;

use Ovebot\Chat\Model\Order\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    /**
     * @dataProvider phones
     */
    public function testLastNineDigits($phone, string $expected)
    {
        $this->assertSame($expected, (new PhoneNormalizer())->core($phone));
    }

    public static function phones(): array
    {
        return [
            'international, with separators' => ['+40 721-234.567', '721234567'],
            'national' => ['0721234567', '721234567'],
            'international with 00' => ['0040721234567', '721234567'],
            'brackets and slash' => ['(0721) 234/567', '721234567'],
            'exactly nine digits' => ['721234567', '721234567'],
            'longer foreign number' => ['+1 (555) 123-4567', '551234567'],
            'integer' => [721234567, '721234567'],
            'eight digits' => ['21234567', ''],
            'nine digits with a leading zero' => ['021234567', ''],
            'text only' => ['call me', ''],
            'empty' => ['', ''],
            'array' => [['0721234567'], ''],
            'null' => [null, ''],
        ];
    }
}
