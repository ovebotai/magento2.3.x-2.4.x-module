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

use Ovebot\Chat\Model\ResourceModel\RateLimit as RateLimitResource;
use Psr\Log\LoggerInterface;

/**
 * Blocks a client of the order endpoint after too many failed authentications: 10 within an hour block the IP
 * for an hour. The block is for wrong credentials only: the endpoint lets the right ones through from a blocked
 * address too (Controller\Orders\Index).
 *
 * The IP is kept only as a hash. A successful authentication forgets the client; regenerating the credentials
 * empties the table (Integration::regenerateOrderCreds()), so Ovebot.ai is not kept out by calls it made with the
 * old ones. A database error never turns into a failed request: the endpoint then works without the limit.
 */
class RateLimiter
{
    public const MAX_FAILURES = 10;

    /**
     * Seconds in which the failures are counted
     */
    public const WINDOW = 3600;

    /**
     * Seconds a client stays blocked
     */
    public const BLOCK = 3600;

    /**
     * One failure in this many also removes the rows that no longer count
     */
    private const CLEAN_ONE_IN = 20;

    private const HASH_PREFIX = 'ovebot_chat|';

    /**
     * @var RateLimitResource
     */
    private $resource;

    /**
     * @var Random
     */
    private $random;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RateLimitResource $resource
     * @param Random $random
     * @param LoggerInterface $logger
     */
    public function __construct(RateLimitResource $resource, Random $random, LoggerInterface $logger)
    {
        $this->resource = $resource;
        $this->random = $random;
        $this->logger = $logger;
    }

    /**
     * Whether the client is blocked
     *
     * @param string $ip
     * @param int $now unix time
     * @return bool
     */
    public function isBlocked(string $ip, int $now): bool
    {
        try {
            $row = $this->resource->getRow($this->hash($ip));
        } catch (\Exception $e) {
            $this->logFailure('read', $e);

            return false;
        }

        return $row !== null && $row['blocked_until'] > $now;
    }

    /**
     * Count a failed authentication; the tenth within the window blocks the client
     *
     * @param string $ip
     * @param int $now unix time
     * @return void
     */
    public function recordFailure(string $ip, int $now)
    {
        try {
            $hash = $this->hash($ip);
            $row = $this->resource->getRow($hash);

            $failures = 1;
            $windowStart = $now;
            if ($row !== null && $row['window_start'] + self::WINDOW > $now) {
                $failures = $row['failures'] + 1;
                $windowStart = $row['window_start'];
            }
            $blockedUntil = $failures >= self::MAX_FAILURES ? $now + self::BLOCK : 0;

            $this->resource->saveRow($hash, $failures, $windowStart, $blockedUntil);

            if ($this->random->number(1, self::CLEAN_ONE_IN) === 1) {
                $this->resource->deleteExpired($now, self::WINDOW);
            }
        } catch (\Exception $e) {
            $this->logFailure('write', $e);
        }
    }

    /**
     * Forget the failures of the client, after a successful authentication
     *
     * @param string $ip
     * @return void
     */
    public function reset(string $ip)
    {
        try {
            $this->resource->deleteRow($this->hash($ip));
        } catch (\Exception $e) {
            $this->logFailure('reset', $e);
        }
    }

    /**
     * Key of a client in the table: the IP is not stored
     *
     * @param string $ip
     * @return string 64 hex characters
     */
    public function hash(string $ip): string
    {
        return hash('sha256', self::HASH_PREFIX . trim($ip));
    }

    /**
     * Log a database error; never the IP
     *
     * @param string $operation
     * @param \Exception $e
     * @return void
     */
    private function logFailure(string $operation, \Exception $e)
    {
        $this->logger->warning(sprintf(
            'Order endpoint rate limit could not %s: %s: %s',
            $operation,
            get_class($e),
            $e->getMessage()
        ));
    }
}
