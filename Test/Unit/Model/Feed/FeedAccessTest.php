<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Feed;

use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\Feed\FeedAccess;
use PHPUnit\Framework\TestCase;

class FeedAccessTest extends TestCase
{
    private const HASH = '0123456789abcdef0123456789abcdef';

    private function connection(
        string $hash,
        bool $chat = true,
        bool $builtin = true,
        bool $recommend = true
    ): Connection {
        $connection = $this->createMock(Connection::class);
        $connection->method('getFeedHash')->willReturn($hash);
        $connection->method('isChatEnabled')->willReturn($chat);
        $connection->method('isProductsBuiltin')->willReturn($builtin);
        $connection->method('isProductsRecommend')->willReturn($recommend);

        return $connection;
    }

    public function testOpenForTheHashOfTheShop()
    {
        $this->assertTrue((new FeedAccess())->isAllowed($this->connection(self::HASH), self::HASH));
    }

    /**
     * @dataProvider wrongHashes
     */
    public function testClosedForAnyOtherHash(string $given)
    {
        $this->assertFalse((new FeedAccess())->isAllowed($this->connection(self::HASH), $given));
    }

    public static function wrongHashes(): array
    {
        return [
            'empty' => [''],
            'another hash' => [str_repeat('a', 32)],
            'upper case' => [strtoupper(self::HASH)],
            'with a line break' => [self::HASH . "\n"],
            'shorter' => [substr(self::HASH, 0, 31)],
            'longer' => [self::HASH . '0'],
        ];
    }

    public function testClosedWhileTheShopHasNoHash()
    {
        $this->assertFalse((new FeedAccess())->isAllowed($this->connection(''), ''));
        $this->assertFalse((new FeedAccess())->isAllowed($this->connection(''), self::HASH));
    }

    /**
     * @dataProvider switches
     */
    public function testClosedWhenOvebotDoesNotReadTheCatalog(bool $chat, bool $builtin, bool $recommend)
    {
        $connection = $this->connection(self::HASH, $chat, $builtin, $recommend);

        $this->assertFalse((new FeedAccess())->isAllowed($connection, self::HASH));
    }

    public static function switches(): array
    {
        return [
            'chat switched off' => [false, true, true],
            'feed of the merchant' => [true, false, true],
            'recommendations switched off' => [true, true, false],
        ];
    }
}
