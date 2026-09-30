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

use Ovebot\Chat\Model\Order\OrderIdentifier;
use PHPUnit\Framework\TestCase;

class OrderIdentifierTest extends TestCase
{
    /**
     * @dataProvider identifiers
     */
    public function testCandidates($raw, ?array $expected)
    {
        $this->assertSame($expected, (new OrderIdentifier())->parse($raw));
    }

    public static function identifiers(): array
    {
        return [
            'Magento order number with a hash' => [
                '#000000123',
                ['increment_ids' => ['000000123'], 'entity_id' => 123],
            ],
            'hash and spaces' => [
                '  # 000000123 ',
                ['increment_ids' => ['000000123'], 'entity_id' => 123],
            ],
            'short number is also tried padded' => [
                '123',
                ['increment_ids' => ['123', '000000123'], 'entity_id' => 123],
            ],
            'number of a second store view' => [
                '200000045',
                ['increment_ids' => ['200000045'], 'entity_id' => 200000045],
            ],
            'number with a prefix' => [
                'ORD-12',
                ['increment_ids' => ['ORD-12'], 'entity_id' => null],
            ],
            'letters keep their case' => [
                'inv_77a',
                ['increment_ids' => ['inv_77a'], 'entity_id' => null],
            ],
            'text around the number' => [
                'comanda nr. 45',
                ['increment_ids' => [], 'entity_id' => 45],
            ],
            'integer' => [
                45,
                ['increment_ids' => ['45', '000000045'], 'entity_id' => 45],
            ],
            'zeros only' => [
                '0',
                ['increment_ids' => ['0', '000000000'], 'entity_id' => null],
            ],
            'longest order number' => [
                str_repeat('A', 50),
                ['increment_ids' => [str_repeat('A', 50)], 'entity_id' => null],
            ],
            'too long for an order number, no digits' => [str_repeat('A', 51), null],
            'too long, with digits' => [
                str_repeat('A', 51) . '7',
                ['increment_ids' => [], 'entity_id' => 7],
            ],
            'too large for an id' => [
                '99999999999',
                ['increment_ids' => ['99999999999'], 'entity_id' => null],
            ],
            'empty' => ['', null],
            'hash only' => ['#', null],
            'spaces only' => ['   ', null],
            'text without digits' => ['my order', null],
            'array' => [['123'], null],
            'null' => [null, null],
            'line break at the end is trimmed' => [
                "ORD-12\n",
                ['increment_ids' => ['ORD-12'], 'entity_id' => null],
            ],
        ];
    }
}
