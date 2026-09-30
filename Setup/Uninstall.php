<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Setup;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use Ovebot\Chat\Model\IntegrationFactory;
use Ovebot\Chat\Model\Util\ErrorSummary;
use Psr\Log\LoggerInterface;

/**
 * Removes the data of the module; run by "bin/magento module:uninstall --remove-data Ovebot_Chat"
 *
 * First the store is disconnected from Ovebot.ai, with a short timeout and without ever stopping the uninstall:
 * the account then shows the store as disconnected. Then the three tables, the options saved in
 * Stores > Configuration and the product feed files in var/ovebot_chat are removed. The log file
 * (var/log/ovebot_chat.log) stays.
 */
class Uninstall implements UninstallInterface
{
    /**
     * Tables of the module (etc/db_schema.xml)
     */
    public const TABLES = ['ovebot_chat_rate_limit', 'ovebot_chat_oauth_state', 'ovebot_chat_connection'];

    /**
     * Rows of core_config_data written from Stores > Configuration. The underscore is escaped: in a LIKE pattern
     * a bare one stands for any character.
     */
    public const CONFIG_PATH = 'ovebot\_chat/%';

    /**
     * Folder of the module in var/: the product feed files
     */
    public const VAR_FOLDER = 'ovebot_chat';

    private const DISCONNECT_TIMEOUT = 5;

    /**
     * @var IntegrationFactory
     */
    private $integrationFactory;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var ErrorSummary
     */
    private $errorSummary;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param IntegrationFactory $integrationFactory
     * @param Filesystem $filesystem
     * @param ErrorSummary $errorSummary
     * @param LoggerInterface $logger
     */
    public function __construct(
        IntegrationFactory $integrationFactory,
        Filesystem $filesystem,
        ErrorSummary $errorSummary,
        LoggerInterface $logger
    ) {
        $this->integrationFactory = $integrationFactory;
        $this->filesystem = $filesystem;
        $this->errorSummary = $errorSummary;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $this->disconnect($setup);

        $connection = $setup->getConnection();
        foreach (self::TABLES as $table) {
            $connection->dropTable($setup->getTable($table));
        }
        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => self::CONFIG_PATH]);

        $this->deleteFiles();

        $setup->endSetup();
    }

    /**
     * Disconnect from Ovebot.ai, when a connection was saved
     *
     * @param SchemaSetupInterface $setup
     * @return void
     */
    private function disconnect(SchemaSetupInterface $setup)
    {
        if (!$setup->getConnection()->isTableExists($setup->getTable('ovebot_chat_connection'))) {
            return;
        }

        try {
            $integration = $this->integrationFactory->create();
            if ($integration->getConnection()->getId()) {
                $integration->disconnectQuietly(self::DISCONNECT_TIMEOUT);
            }
        } catch (\Throwable $e) {
            // the data is removed even when the disconnect could not be tried
            $this->logger->warning('Uninstall: disconnect skipped, ' . $this->errorSummary->describe($e));
        }
    }

    /**
     * Delete var/ovebot_chat
     *
     * @return void
     */
    private function deleteFiles()
    {
        try {
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->delete(self::VAR_FOLDER);
        } catch (\Exception $e) {
            $this->logger->warning('Uninstall: var/' . self::VAR_FOLDER . ' not deleted, '
                . $this->errorSummary->describe($e));
        }
    }
}
