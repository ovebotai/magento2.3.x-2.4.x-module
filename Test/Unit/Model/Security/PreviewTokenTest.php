<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Security;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\ResourceModel\Connection as ConnectionResource;
use Ovebot\Chat\Model\Security\PreviewToken;
use Ovebot\Chat\Model\Security\Random;
use PHPUnit\Framework\TestCase;

class PreviewTokenTest extends TestCase
{
    private const NOW = 1790000000;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var array arguments of ConnectionResource::savePreviewToken()
     */
    private $written = [];

    /**
     * @var bool
     */
    private $readFails = false;

    protected function setUp(): void
    {
        $this->connection = new Connection(
            $this->createMock(Context::class),
            $this->createMock(Registry::class),
            $this->createMock(EncryptorInterface::class),
            new Json(),
            $this->createMock(ConnectionResource::class)
        );
        $this->connection->setId(7);
        $this->written = [];
        $this->readFails = false;
    }

    private function previewToken(): PreviewToken
    {
        $connections = $this->createMock(ConnectionRepository::class);
        $connections->method('get')->willReturnCallback(function () {
            if ($this->readFails) {
                throw new \RuntimeException('database is down');
            }

            return $this->connection;
        });
        // the whole connection is never saved: only the two columns of the token
        $connections->expects($this->never())->method('save');

        $resource = $this->createMock(ConnectionResource::class);
        $resource->method('savePreviewToken')->willReturnCallback(function (...$args) {
            $this->written[] = $args;
        });

        return new PreviewToken($connections, $resource, new Random());
    }

    public function testIssueWritesOnlyTheTokenAndItsExpiry()
    {
        $token = $this->previewToken()->issue(self::NOW);

        $this->assertMatchesRegularExpression(PreviewToken::PATTERN, $token);
        $this->assertSame([[7, $token, self::NOW + PreviewToken::TTL]], $this->written);
        $this->assertSame($token, $this->connection->getPreviewToken(self::NOW));
    }

    public function testANewTokenReplacesTheOldOne()
    {
        $previewToken = $this->previewToken();
        $first = $previewToken->issue(self::NOW);
        $second = $previewToken->issue(self::NOW + 5);

        $this->assertNotSame($first, $second);
        $this->assertFalse($previewToken->isValid($first, self::NOW + 6));
        $this->assertTrue($previewToken->isValid($second, self::NOW + 6));
    }

    public function testNoTokenWithoutASavedConnection()
    {
        $this->connection->setId(null);

        $this->assertSame('', $this->previewToken()->issue(self::NOW));
        $this->assertSame([], $this->written);
    }

    public function testValidUntilItExpires()
    {
        $previewToken = $this->previewToken();
        $token = $previewToken->issue(self::NOW);

        $this->assertTrue($previewToken->isValid($token, self::NOW));
        $this->assertTrue($previewToken->isValid($token, self::NOW + PreviewToken::TTL - 1));
        $this->assertFalse($previewToken->isValid($token, self::NOW + PreviewToken::TTL));
    }

    /**
     * @return array
     */
    public static function invalidTokens(): array
    {
        return [
            'empty' => [''],
            'other token' => [str_repeat('0', 32)],
            'upper case' => ['UPPER'],
            'too short' => [str_repeat('a', 31)],
            'line break' => ["TOKEN\n"],
        ];
    }

    /**
     * @dataProvider invalidTokens
     * @param string $given
     */
    public function testOtherValuesAreRefused(string $given)
    {
        $previewToken = $this->previewToken();
        $token = $previewToken->issue(self::NOW);
        $given = str_replace(['TOKEN', 'UPPER'], [$token, strtoupper($token)], $given);

        $this->assertFalse($previewToken->isValid($given, self::NOW));
    }

    public function testNothingIsValidWithoutAToken()
    {
        $this->assertFalse($this->previewToken()->isValid(str_repeat('a', 32), self::NOW));
    }

    public function testAConnectionThatCannotBeReadIsNotValid()
    {
        $previewToken = $this->previewToken();
        $token = $previewToken->issue(self::NOW);
        $this->readFails = true;

        $this->assertFalse($previewToken->isValid($token, self::NOW));
    }
}
