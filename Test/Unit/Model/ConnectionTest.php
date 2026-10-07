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

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\ResourceModel\Connection as ConnectionResource;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    /**
     * @param array $data
     * @return Connection
     */
    private function connection(array $data = []): Connection
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(function ($value) {
            return 'enc:' . strrev((string) $value);
        });
        $encryptor->method('decrypt')->willReturnCallback(function ($value) {
            return strrev(substr((string) $value, 4));
        });

        return new Connection(
            $this->createMock(Context::class),
            $this->createMock(Registry::class),
            $encryptor,
            new Json(),
            $this->createMock(ConnectionResource::class),
            null,
            $data
        );
    }

    public function testSecretsAreStoredEncrypted()
    {
        $connection = $this->connection();
        $connection->setTokens('access-1', 'refresh-1', 1700000000);
        $connection->setOrderCredentials('shop_ro_1a2b3c4d', 'secret-pass');

        $this->assertSame('access-1', $connection->getAccessToken());
        $this->assertSame('refresh-1', $connection->getRefreshToken());
        $this->assertSame('secret-pass', $connection->getOrderPass());
        $this->assertSame(1700000000, $connection->getTokenExpires());

        $secrets = ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'order_pass' => 'secret-pass'];
        foreach ($secrets as $key => $clear) {
            $this->assertNotSame($clear, $connection->getData($key));
            $this->assertStringNotContainsString($clear, (string) $connection->getData($key));
        }
        // the user of the order endpoint is not a secret on its own, so it stays in clear
        $this->assertSame('shop_ro_1a2b3c4d', $connection->getData('order_user'));
    }

    public function testClearTokensKeepsWorkspaceAgentAndSwitches()
    {
        $connection = $this->connection();
        $connection->setTokens('access-1', 'refresh-1', 1700000000);
        $connection->setWorkspace('k3v9q2xa');
        $connection->setAgent('abc123');
        $connection->setChatEnabled(true);
        $this->assertTrue($connection->isConnected());

        $connection->clearTokens();

        $this->assertSame('', $connection->getAccessToken());
        $this->assertSame('', $connection->getRefreshToken());
        $this->assertNull($connection->getData('access_token'));
        $this->assertNull($connection->getData('refresh_token'));
        $this->assertSame(0, $connection->getTokenExpires());
        $this->assertFalse($connection->isConnected());
        $this->assertSame('k3v9q2xa', $connection->getWorkspace());
        $this->assertSame('abc123', $connection->getAgent());
        $this->assertTrue($connection->isChatEnabled());
    }

    /**
     * @dataProvider workspaces
     */
    public function testWorkspaceIsValidated(string $given, string $expected)
    {
        $this->assertSame($expected, $this->connection()->setWorkspace($given)->getWorkspace());
        // a value that reached the database some other way is not trusted either
        $this->assertSame($expected, $this->connection(['workspace' => $given])->getWorkspace());
    }

    /**
     * @dataProvider workspaces
     */
    public function testLastWorkspaceIsValidatedTheSameWay(string $given, string $expected)
    {
        $this->assertSame($expected, $this->connection()->setLastWorkspace($given)->getLastWorkspace());
        $this->assertSame($expected, $this->connection(['last_workspace' => $given])->getLastWorkspace());
    }

    public function testLastWorkspaceIsKeptApartFromTheWorkspace()
    {
        $connection = $this->connection();
        $connection->setWorkspace('k3v9q2xa')->setLastWorkspace('k3v9q2xa');
        $connection->setWorkspace('');

        $this->assertSame('', $connection->getWorkspace());
        $this->assertSame('k3v9q2xa', $connection->getLastWorkspace());
    }

    public static function workspaces(): array
    {
        return [
            'slug' => ['k3v9q2xa', 'k3v9q2xa'],
            'with dash' => ['my-shop-2', 'my-shop-2'],
            'empty' => ['', ''],
            'upper case' => ['MyShop', ''],
            'dot' => ['evil.example.com', ''],
            'slash' => ['shop/x', ''],
            'space' => ['my shop', ''],
        ];
    }

    /**
     * @dataProvider switches
     */
    public function testSwitchesNotSetCountAsOn($stored, bool $expected)
    {
        $connection = $this->connection([
            'products_builtin' => $stored,
            'products_recommend' => $stored,
            'order_enabled' => $stored,
            'add_to_cart' => $stored,
        ]);

        $this->assertSame($expected, $connection->isProductsBuiltin());
        $this->assertSame($expected, $connection->isProductsRecommend());
        $this->assertSame($expected, $connection->isOrderEnabled());
        $this->assertSame($expected, $connection->isAddToCart());
    }

    public static function switches(): array
    {
        return [
            'not set' => [null, true],
            'on, as read from the database' => ['1', true],
            'off, as read from the database' => ['0', false],
            'on' => [1, true],
            'off' => [0, false],
        ];
    }

    public function testSwitchSetters()
    {
        $connection = $this->connection();

        $connection->setProductsBuiltin(false);
        $this->assertSame(0, $connection->getData('products_builtin'));
        $this->assertFalse($connection->isProductsBuiltin());

        $connection->setProductsBuiltin(null);
        $this->assertNull($connection->getData('products_builtin'));
        $this->assertTrue($connection->isProductsBuiltin());

        $connection->setAddToCart(false);
        $this->assertSame(0, $connection->getData('add_to_cart'));
        $this->assertFalse($connection->isAddToCart());
        $connection->setAddToCart(null);
        $this->assertTrue($connection->isAddToCart());

        // chat and setup are plain flags: not set means off
        $this->assertFalse($connection->isChatEnabled());
        $this->assertFalse($connection->isSetupComplete());
    }

    public function testJsonColumns()
    {
        $connection = $this->connection();
        $connection->setWidget(['accent_color' => '#2271B1', 'subtitle' => 'Bună ziua']);
        $connection->setKbPageIds(['4', 7, 7, 0, -2, 'x', [1]]);
        $connection->setLastPush(['feed_url' => 'https://shop.test/f', 'api_url' => 'https://shop.test/o']);

        $this->assertSame(['accent_color' => '#2271B1', 'subtitle' => 'Bună ziua'], $connection->getWidget());
        $this->assertSame([4, 7], $connection->getKbPageIds());
        $this->assertSame('https://shop.test/o', $connection->getLastPush()['api_url']);
        $this->assertSame('[4,7]', $connection->getData('kb_page_ids'));

        $connection->setWidget([]);
        $this->assertNull($connection->getData('widget'));
        $this->assertSame([], $connection->getWidget());
    }

    public function testBrokenJsonReadsAsEmpty()
    {
        $connection = $this->connection(['widget' => '{broken', 'kb_page_ids' => '"text"', 'last_push' => '']);

        $this->assertSame([], $connection->getWidget());
        $this->assertSame([], $connection->getKbPageIds());
        $this->assertSame([], $connection->getLastPush());
    }

    public function testUniqueKeysAreStoredAsNullWhenEmpty()
    {
        $connection = $this->connection();
        $connection->setFeedHash('');
        $connection->setOrderCredentials('', '');
        $connection->setAgent('');

        // unique columns: several rows may hold NULL, but not the same empty string
        $this->assertNull($connection->getData('feed_hash'));
        $this->assertNull($connection->getData('order_user'));
        $this->assertNull($connection->getData('order_pass'));
        $this->assertNull($connection->getData('agent'));
        $this->assertSame('', $connection->getFeedHash());
        $this->assertSame('', $connection->getOrderUser());
    }

    public function testOauthErrorAndPreviewTokenExpire()
    {
        $connection = $this->connection();
        $connection->setOauthError('Authorization was cancelled.', 1000);
        $connection->setPreviewToken('0123456789abcdef0123456789abcdef', 1000);

        $this->assertSame('Authorization was cancelled.', $connection->getOauthError(999));
        $this->assertSame('', $connection->getOauthError(1000));
        $this->assertSame('0123456789abcdef0123456789abcdef', $connection->getPreviewToken(999));
        $this->assertSame('', $connection->getPreviewToken(1000));

        $connection->setOauthError('', 5000);
        $connection->setPreviewToken('', 5000);
        $this->assertNull($connection->getData('oauth_error'));
        $this->assertSame(0, $connection->getData('oauth_error_expires'));
        $this->assertNull($connection->getData('preview_token'));
        $this->assertSame(0, $connection->getData('preview_expires'));
    }
}
