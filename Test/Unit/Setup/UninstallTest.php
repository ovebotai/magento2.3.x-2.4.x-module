<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Setup;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\IntegrationFactory;
use Ovebot\Chat\Model\Util\ErrorSummary;
use Ovebot\Chat\Setup\Uninstall;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UninstallTest extends TestCase
{
    /**
     * @var string[] what happened, in order
     */
    private $calls = [];

    /**
     * @var string[]
     */
    private $logged = [];

    /**
     * @var int calls of IntegrationFactory::create()
     */
    private $created = 0;

    /**
     * @var bool
     */
    private $tableExists = true;

    /**
     * @var int|null
     */
    private $connectionId = 1;

    /**
     * @var \Throwable|null
     */
    private $factoryError;

    /**
     * @var \Exception|null
     */
    private $deleteError;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->logged = [];
        $this->created = 0;
        $this->tableExists = true;
        $this->connectionId = 1;
        $this->factoryError = null;
        $this->deleteError = null;
    }

    public function testDisconnectsThenRemovesTablesConfigurationAndFiles()
    {
        $this->uninstall();

        $this->assertSame(
            [
                'startSetup',
                'disconnectQuietly(5)',
                'dropTable(m_ovebot_chat_rate_limit)',
                'dropTable(m_ovebot_chat_oauth_state)',
                'dropTable(m_ovebot_chat_connection)',
                'delete(m_core_config_data, path LIKE ? ovebot\_chat/%)',
                'deleteFolder(var, ovebot_chat)',
                'endSetup',
            ],
            $this->calls
        );
        $this->assertSame([], $this->logged);
    }

    public function testNoDisconnectWithoutASavedConnection()
    {
        $this->connectionId = null;

        $this->uninstall();

        $this->assertNotContains('disconnectQuietly(5)', $this->calls);
        $this->assertContains('dropTable(m_ovebot_chat_connection)', $this->calls);
    }

    public function testNoDisconnectWhenTheTableIsAlreadyGone()
    {
        $this->tableExists = false;

        $this->uninstall();

        $this->assertSame(0, $this->created);
        $this->assertContains('delete(m_core_config_data, path LIKE ? ovebot\_chat/%)', $this->calls);
    }

    public function testAFailedDisconnectDoesNotStopTheUninstallAndLogsNoMessage()
    {
        $this->factoryError = new \RuntimeException('secret-token-in-message');

        $this->uninstall();

        $this->assertContains('dropTable(m_ovebot_chat_connection)', $this->calls);
        $this->assertContains('endSetup', $this->calls);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('disconnect skipped', $this->logged[0]);
        $this->assertStringNotContainsString('secret-token-in-message', $this->logged[0]);
    }

    public function testAFolderThatCannotBeDeletedIsLogged()
    {
        $this->deleteError = new FileSystemException(__('The folder cannot be deleted'));

        $this->uninstall();

        $this->assertContains('endSetup', $this->calls);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('var/ovebot_chat not deleted', $this->logged[0]);
    }

    private function uninstall()
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('isTableExists')->willReturnCallback(function () {
            return $this->tableExists;
        });
        $adapter->method('dropTable')->willReturnCallback(function ($table) {
            $this->calls[] = 'dropTable(' . $table . ')';
            return true;
        });
        $adapter->method('delete')->willReturnCallback(function ($table, $where) {
            $this->calls[] = 'delete(' . $table . ', ' . key($where) . ' ' . current($where) . ')';
            return 0;
        });

        $setup = $this->createMock(SchemaSetupInterface::class);
        $setup->method('getConnection')->willReturn($adapter);
        $setup->method('getTable')->willReturnCallback(function ($name) {
            return 'm_' . $name;
        });
        $setup->method('startSetup')->willReturnCallback(function () use ($setup) {
            $this->calls[] = 'startSetup';
            return $setup;
        });
        $setup->method('endSetup')->willReturnCallback(function () use ($setup) {
            $this->calls[] = 'endSetup';
            return $setup;
        });

        $connection = $this->createMock(Connection::class);
        $connection->method('getId')->willReturnCallback(function () {
            return $this->connectionId;
        });
        $integration = $this->createMock(Integration::class);
        $integration->method('getConnection')->willReturn($connection);
        $integration->method('disconnectQuietly')->willReturnCallback(function ($timeout) {
            $this->calls[] = 'disconnectQuietly(' . $timeout . ')';
        });
        $factory = $this->createMock(IntegrationFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($integration) {
            $this->created++;
            if ($this->factoryError !== null) {
                throw $this->factoryError;
            }
            return $integration;
        });

        $folder = $this->createMock(WriteInterface::class);
        $folder->method('delete')->willReturnCallback(function ($path) {
            if ($this->deleteError !== null) {
                throw $this->deleteError;
            }
            $this->calls[] = 'deleteFolder(var, ' . $path . ')';
            return true;
        });
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::VAR_DIR)->willReturn($folder);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->logged[] = (string) $message;
        });

        (new Uninstall($factory, $filesystem, new ErrorSummary(), $logger))
            ->uninstall($setup, $this->createMock(ModuleContextInterface::class));
    }
}
