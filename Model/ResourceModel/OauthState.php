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
 * Pending OAuth authorizations: state => verifier, return URL, expiry.
 */
class OauthState extends AbstractDb
{
    public const TABLE = 'ovebot_chat_oauth_state';

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
        $this->_init(self::TABLE, 'state');
    }

    /**
     * Add a pending authorization
     *
     * @param array $row state, verifier, return_url, admin_user_id, expires_at
     * @return void
     */
    public function insertState(array $row)
    {
        $this->getConnection()->insert($this->getMainTable(), $row);
    }

    /**
     * Read a pending authorization
     *
     * @param string $state
     * @return array|null
     */
    public function fetchState(string $state): ?array
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getMainTable())->where('state = ?', $state)
        );

        return is_array($row) && $row ? $row : null;
    }

    /**
     * Remove a pending authorization
     *
     * @param string $state
     * @return int number of rows removed
     */
    public function deleteState(string $state): int
    {
        return (int) $this->getConnection()->delete($this->getMainTable(), ['state = ?' => $state]);
    }

    /**
     * Remove the authorizations that expired
     *
     * @param int $now unix time
     * @return void
     */
    public function deleteExpired(int $now)
    {
        $this->getConnection()->delete($this->getMainTable(), ['expires_at < ?' => $now]);
    }

    /**
     * Keep only the newest authorizations
     *
     * @param int $keep
     * @return void
     */
    public function trim(int $keep)
    {
        $connection = $this->getConnection();
        $states = $connection->fetchCol(
            $connection->select()
                ->from($this->getMainTable(), ['state'])
                ->order(['expires_at DESC', 'state ASC'])
        );

        $surplus = array_slice($states, max(0, $keep));
        if ($surplus) {
            $connection->delete($this->getMainTable(), ['state IN (?)' => $surplus]);
        }
    }
}
