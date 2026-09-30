<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Ovebot\Chat\Model\ResourceModel\Connection as ConnectionResource;

/**
 * Loads and saves the connection of the shop. There is one connection for the whole Magento installation.
 */
class ConnectionRepository
{
    /**
     * @var ConnectionFactory
     */
    private $connectionFactory;

    /**
     * @var ConnectionResource
     */
    private $resource;

    /**
     * @var Connection|null
     */
    private $connection;

    /**
     * @param ConnectionFactory $connectionFactory
     * @param ConnectionResource $resource
     */
    public function __construct(ConnectionFactory $connectionFactory, ConnectionResource $resource)
    {
        $this->connectionFactory = $connectionFactory;
        $this->resource = $resource;
    }

    /**
     * The connection of the shop; a new, unsaved one when nothing was saved yet
     *
     * @return Connection
     */
    public function get(): Connection
    {
        if ($this->connection === null) {
            $connection = $this->connectionFactory->create();

            $id = $this->resource->getSingleId();
            if ($id > 0) {
                $this->resource->load($connection, $id);
            }

            $this->connection = $connection;
        }

        return $this->connection;
    }

    /**
     * Read the connection again from the database (used under the token refresh lock)
     *
     * @return Connection
     */
    public function reload(): Connection
    {
        $this->connection = null;

        return $this->get();
    }

    /**
     * Save the connection
     *
     * @param Connection $connection
     * @return Connection
     * @throws CouldNotSaveException
     */
    public function save(Connection $connection): Connection
    {
        try {
            $this->resource->save($connection);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('The Ovebot.ai connection could not be saved.'), $e);
        }
        $this->connection = $connection;

        return $connection;
    }
}
