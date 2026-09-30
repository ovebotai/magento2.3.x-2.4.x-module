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

use Ovebot\Chat\Model\OauthStateStorage;
use Ovebot\Chat\Model\ResourceModel\OauthState as OauthStateResource;
use PHPUnit\Framework\TestCase;

class OauthStateStorageTest extends TestCase
{
    private const NOW = 1700000000;

    /**
     * @var array rows of the table, by state
     */
    private $rows = [];

    /**
     * @var array trim() calls
     */
    private $trims = [];

    protected function setUp(): void
    {
        $this->rows = [];
        $this->trims = [];
    }

    private function storage(): OauthStateStorage
    {
        $resource = $this->createMock(OauthStateResource::class);
        $resource->method('insertState')->willReturnCallback(function (array $row) {
            $this->rows[$row['state']] = $row;
        });
        $resource->method('fetchState')->willReturnCallback(function ($state) {
            return isset($this->rows[$state]) ? $this->rows[$state] : null;
        });
        $resource->method('deleteState')->willReturnCallback(function ($state) {
            $found = isset($this->rows[$state]) ? 1 : 0;
            unset($this->rows[$state]);

            return $found;
        });
        $resource->method('deleteExpired')->willReturnCallback(function ($now) {
            foreach ($this->rows as $state => $row) {
                if ($row['expires_at'] < $now) {
                    unset($this->rows[$state]);
                }
            }
        });
        $resource->method('trim')->willReturnCallback(function ($keep) {
            $this->trims[] = $keep;
        });

        return new OauthStateStorage($resource);
    }

    public function testAddAndPull()
    {
        $storage = $this->storage();
        $state = str_repeat('a', 32);

        $storage->add($state, 'verifier', 'https://shop.test/admin/ovebot_chat/', 7, self::NOW);

        $this->assertSame(self::NOW + OauthStateStorage::TTL, $this->rows[$state]['expires_at']);
        $this->assertSame([OauthStateStorage::MAX_PENDING], $this->trims);

        $this->assertSame(
            [
                'verifier' => 'verifier',
                'return_url' => 'https://shop.test/admin/ovebot_chat/',
                'admin_user_id' => 7,
            ],
            $storage->pull($state, self::NOW + 10)
        );
        $this->assertNull($storage->pull($state, self::NOW + 10), 'the state is used once');
    }

    public function testASecondAuthorizationDoesNotBreakTheFirst()
    {
        $storage = $this->storage();
        $first = str_repeat('a', 32);
        $second = str_repeat('b', 32);

        $storage->add($first, 'v1', 'https://shop.test/1', 7, self::NOW);
        $storage->add($second, 'v2', 'https://shop.test/2', 7, self::NOW + 5);

        $this->assertSame('v1', $storage->pull($first, self::NOW + 10)['verifier']);
        $this->assertSame('v2', $storage->pull($second, self::NOW + 10)['verifier']);
    }

    public function testExpiredStateIsRefusedAndRemoved()
    {
        $storage = $this->storage();
        $state = str_repeat('a', 32);
        $storage->add($state, 'verifier', 'https://shop.test/', 7, self::NOW);

        $this->assertNull($storage->pull($state, self::NOW + OauthStateStorage::TTL + 1));
        $this->assertSame([], $this->rows);
    }

    public function testStateValidUntilTheLastSecond()
    {
        $storage = $this->storage();
        $state = str_repeat('a', 32);
        $storage->add($state, 'verifier', 'https://shop.test/', 7, self::NOW);

        $this->assertNotNull($storage->pull($state, self::NOW + OauthStateStorage::TTL));
    }

    public function testAddRemovesTheExpiredOnes()
    {
        $storage = $this->storage();
        $storage->add(str_repeat('a', 32), 'v1', 'https://shop.test/', 7, self::NOW);
        $storage->add(str_repeat('b', 32), 'v2', 'https://shop.test/', 7, self::NOW + OauthStateStorage::TTL + 1);

        $this->assertSame([str_repeat('b', 32)], array_keys($this->rows));
    }

    /**
     * @dataProvider badStates
     */
    public function testStateWithAnotherFormatIsNotLookedUp(string $state)
    {
        $storage = $this->storage();
        $this->rows[$state] = [
            'state' => $state,
            'verifier' => 'v',
            'return_url' => 'https://shop.test/',
            'expires_at' => self::NOW + 100,
        ];

        $this->assertNull($storage->pull($state, self::NOW));
        $this->assertArrayHasKey($state, $this->rows);
    }

    public static function badStates(): array
    {
        return [
            'empty' => [''],
            'short' => ['abc'],
            'upper case' => [str_repeat('A', 32)],
            'not hex' => [str_repeat('z', 32)],
            'too long' => [str_repeat('a', 33)],
            'with newline' => [str_repeat('a', 32) . "\n"],
        ];
    }

    public function testUnknownState()
    {
        $this->assertNull($this->storage()->pull(str_repeat('a', 32), self::NOW));
    }

    public function testRowWithoutVerifierIsRefused()
    {
        $storage = $this->storage();
        $state = str_repeat('a', 32);
        $this->rows[$state] = [
            'state' => $state,
            'verifier' => '',
            'return_url' => 'https://shop.test/',
            'expires_at' => self::NOW + 100,
        ];

        $this->assertNull($storage->pull($state, self::NOW));
    }
}
