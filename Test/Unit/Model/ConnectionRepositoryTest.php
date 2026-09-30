<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\ConnectionFactory;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\ResourceModel\Connection as ConnectionResource;
use PHPUnit\Framework\TestCase;

class ConnectionRepositoryTest extends TestCase
{
    /**
     * @var int id of the saved row; 0 = nothing saved
     */
    private $savedId = 0;

    /**
     * @var array ids loaded, in order
     */
    private $loads = [];

    /**
     * @var bool
     */
    private $saveFails = false;

    protected function setUp(): void
    {
        $this->savedId = 0;
        $this->loads = [];
        $this->saveFails = false;
    }

    private function repository(): ConnectionRepository
    {
        $factory = $this->getMockBuilder(ConnectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(function () {
            return $this->createMock(Connection::class);
        });

        $resource = $this->createMock(ConnectionResource::class);
        $resource->method('getSingleId')->willReturnCallback(function () {
            return $this->savedId;
        });
        $resource->method('load')->willReturnCallback(function ($connection, $id) use ($resource) {
            $this->loads[] = $id;

            return $resource;
        });
        $resource->method('save')->willReturnCallback(function () use ($resource) {
            if ($this->saveFails) {
                throw new \RuntimeException('SQLSTATE: secret value quoted here');
            }

            return $resource;
        });

        return new ConnectionRepository($factory, $resource);
    }

    public function testNothingSavedGivesANewConnectionWithoutLoading()
    {
        $repository = $this->repository();

        $connection = $repository->get();

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame([], $this->loads);
        $this->assertSame($connection, $repository->get(), 'the same connection during a request');
    }

    public function testTheSavedRowIsLoadedOnce()
    {
        $this->savedId = 4;
        $repository = $this->repository();

        $connection = $repository->get();
        $repository->get();

        $this->assertSame([4], $this->loads);
        $this->assertSame($connection, $repository->get());
    }

    public function testReloadReadsTheDatabaseAgain()
    {
        $this->savedId = 4;
        $repository = $this->repository();

        $first = $repository->get();
        $second = $repository->reload();

        $this->assertSame([4, 4], $this->loads);
        $this->assertNotSame($first, $second);
        $this->assertSame($second, $repository->get());
    }

    public function testSavedConnectionBecomesTheCurrentOne()
    {
        $repository = $this->repository();
        $connection = $this->createMock(Connection::class);

        $this->assertSame($connection, $repository->save($connection));
        $this->assertSame($connection, $repository->get());
        $this->assertSame([], $this->loads);
    }

    public function testSaveFailureKeepsTheDatabaseMessageOutOfTheError()
    {
        $this->saveFails = true;

        try {
            $this->repository()->save($this->createMock(Connection::class));
            $this->fail('save() did not raise');
        } catch (CouldNotSaveException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }
}
