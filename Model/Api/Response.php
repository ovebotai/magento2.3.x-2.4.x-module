<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Api;

/**
 * Answer of Ovebot.ai: HTTP status and decoded JSON body. A body that is not JSON reads as empty.
 */
class Response
{
    /**
     * @var int
     */
    private $status;

    /**
     * @var array
     */
    private $body;

    /**
     * @param int $status
     * @param array $body
     */
    public function __construct(int $status, array $body = [])
    {
        $this->status = $status;
        $this->body = $body;
    }

    /**
     * HTTP status
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Decoded body
     *
     * @return array
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * Whether the status is 2xx
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
