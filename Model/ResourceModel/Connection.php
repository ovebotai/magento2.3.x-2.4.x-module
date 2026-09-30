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
 * Connection resource model. The shop has one connection, so the table holds one row.
 */
class Connection extends AbstractDb
{
    public const TABLE = 'ovebot_chat_connection';

    /**
     * Define the main table
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(self::TABLE, 'connection_id');
    }

    /**
     * Id of the row that holds the connection; 0 when nothing was saved yet
     *
     * The oldest row wins, so the answer stays the same if a second row ever gets written.
     *
     * @return int
     */
    public function getSingleId(): int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), [$this->getIdFieldName()])
            ->order($this->getIdFieldName() . ' ASC')
            ->limit(1);

        return (int) $connection->fetchOne($select);
    }

    /**
     * Write the preview token of a row, and only it
     *
     * Saving the whole connection would also write the tokens read at the start of the request, and could undo a
     * token refresh made meanwhile by another request.
     *
     * @param int $id
     * @param string $token empty to clear it
     * @param int $expires unix time
     * @return void
     */
    public function savePreviewToken(int $id, string $token, int $expires)
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            [
                'preview_token' => $token !== '' ? $token : null,
                'preview_expires' => $token !== '' ? max(0, $expires) : 0,
            ],
            [$this->getIdFieldName() . ' = ?' => $id]
        );
    }
}
