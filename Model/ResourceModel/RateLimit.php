<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Failed authentications on the order endpoint, by hashed client IP.
 */
class RateLimit extends AbstractDb
{
    public const TABLE = 'ovebot_chat_rate_limit';

    /**
     * @var bool
     */
    protected $_isPkAutoIncrement = false;

    /**
     * Define the main table
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(self::TABLE, 'ip_hash');
    }

    /**
     * The row of a client
     *
     * @param string $ipHash
     * @return int[]|null ['failures' => int, 'window_start' => int, 'blocked_until' => int]; null without a row
     */
    public function getRow(string $ipHash): ?array
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->getMainTable(), ['failures', 'window_start', 'blocked_until'])
                ->where('ip_hash = ?', $ipHash)
        );

        return is_array($row) && $row
            ? [
                'failures' => (int) $row['failures'],
                'window_start' => (int) $row['window_start'],
                'blocked_until' => (int) $row['blocked_until'],
            ]
            : null;
    }

    /**
     * Write the row of a client, new or not
     *
     * @param string $ipHash
     * @param int $failures
     * @param int $windowStart unix time
     * @param int $blockedUntil unix time; 0 when not blocked
     * @return void
     */
    public function saveRow(string $ipHash, int $failures, int $windowStart, int $blockedUntil)
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getMainTable(),
            [
                'ip_hash' => $ipHash,
                'failures' => max(0, $failures),
                'window_start' => max(0, $windowStart),
                'blocked_until' => max(0, $blockedUntil),
            ],
            ['failures', 'window_start', 'blocked_until']
        );
    }

    /**
     * Forget a client
     *
     * @param string $ipHash
     * @return int number of rows removed
     */
    public function deleteRow(string $ipHash): int
    {
        return (int) $this->getConnection()->delete($this->getMainTable(), ['ip_hash = ?' => $ipHash]);
    }

    /**
     * Remove the rows that no longer count: not blocked any more and with a window that has ended
     *
     * @param int $now unix time
     * @param int $window length of the counting window, in seconds
     * @return int number of rows removed
     */
    public function deleteExpired(int $now, int $window): int
    {
        return (int) $this->getConnection()->delete(
            $this->getMainTable(),
            ['blocked_until <= ?' => $now, 'window_start <= ?' => $now - $window]
        );
    }

    /**
     * Forget every failed authentication and every block
     *
     * @return int number of rows removed
     */
    public function clearAll(): int
    {
        return (int) $this->getConnection()->delete($this->getMainTable());
    }
}
