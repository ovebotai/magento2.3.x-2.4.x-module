<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Api;

use Ovebot\Chat\Model\Api\ErrorParser;
use Ovebot\Chat\Model\Api\Response;
use PHPUnit\Framework\TestCase;

class ErrorParserTest extends TestCase
{
    /**
     * @dataProvider messages
     */
    public function testMessage(int $status, array $body, string $expected)
    {
        $this->assertSame($expected, (new ErrorParser())->message(new Response($status, $body)));
    }

    public static function messages(): array
    {
        return [
            'error object with fields' => [
                422,
                ['error' => ['code' => 'invalid', 'message' => 'msg', 'fields' => ['x' => ['a', 'b']]]],
                'msg a b',
            ],
            'oauth error with description' => [
                400,
                ['error' => 'invalid_grant', 'error_description' => 'desc'],
                'desc',
            ],
            'oauth error without description' => [400, ['error' => 'invalid_grant'], 'invalid_grant'],
            'message' => [400, ['message' => 'm'], 'm'],
            'errors by field' => [400, ['errors' => ['a' => ['f1'], 'b' => 'f2']], 'f1 f2'],
            'message and errors' => [422, ['message' => 'Invalid.', 'errors' => ['a' => ['f1']]], 'Invalid. f1'],
            'repeated text once' => [422, ['message' => 'same', 'errors' => ['a' => ['same']]], 'same'],
            'empty body' => [500, [], 'HTTP 500'],
            'values that are not text' => [400, ['error' => ['message' => ['x']], 'message' => ['y']], 'HTTP 400'],
        ];
    }

    public function testCode()
    {
        $parser = new ErrorParser();

        $this->assertSame(
            'kb_limit_reached',
            $parser->code(new Response(409, ['error' => ['code' => 'kb_limit_reached']]))
        );
        $this->assertSame('', $parser->code(new Response(400, ['error' => 'invalid_grant'])));
        $this->assertSame('', $parser->code(new Response(400, [])));
    }

    /**
     * @dataProvider quotas
     */
    public function testIsQuota(int $status, array $body, bool $expected)
    {
        $this->assertSame($expected, (new ErrorParser())->isQuota(new Response($status, $body)));
    }

    public static function quotas(): array
    {
        return [
            'by code' => [400, ['error' => ['code' => 'kb_limit_reached']], true],
            'by code, quota' => [400, ['error' => ['code' => 'QUOTA_EXCEEDED']], true],
            'by status 402' => [402, [], true],
            'by status 409' => [409, [], true],
            'by status 429' => [429, [], true],
            'by message' => [400, ['message' => 'Plan limit reached'], true],
            'not a quota' => [400, ['message' => 'Bad slug'], false],
            'server error' => [500, [], false],
        ];
    }
}
