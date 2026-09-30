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

use Magento\Framework\App\Request\Http as HttpRequest;
use Ovebot\Chat\Model\Security\BasicAuth;
use PHPUnit\Framework\TestCase;

class BasicAuthTest extends TestCase
{
    private function request(array $server, $authorization = false): HttpRequest
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getServer')->willReturnCallback(function ($name) use ($server) {
            return isset($server[$name]) ? $server[$name] : null;
        });
        $request->method('getHeader')->willReturnCallback(function ($name) use ($authorization) {
            return strcasecmp($name, 'Authorization') === 0 ? $authorization : false;
        });

        return $request;
    }

    public function testCredentialsFilledByPhp()
    {
        $request = $this->request(['PHP_AUTH_USER' => 'shop_1a2b3c4d', 'PHP_AUTH_PW' => 'secret']);

        $this->assertSame(['shop_1a2b3c4d', 'secret'], (new BasicAuth())->credentials($request));
    }

    public function testCredentialsFromTheHeaderUnderPhpFpm()
    {
        $request = $this->request([], 'Basic ' . base64_encode('shop_1a2b3c4d:pa:ss'));

        // the password may hold a colon: only the first one separates
        $this->assertSame(['shop_1a2b3c4d', 'pa:ss'], (new BasicAuth())->credentials($request));
    }

    public function testCredentialsFromTheHeaderAfterARewrite()
    {
        $request = $this->request(['REDIRECT_HTTP_AUTHORIZATION' => 'basic ' . base64_encode('user:secret')]);

        $this->assertSame(['user', 'secret'], (new BasicAuth())->credentials($request));
    }

    public function testNoCredentials()
    {
        $this->assertNull((new BasicAuth())->credentials($this->request([])));
    }

    /**
     * @dataProvider badHeaders
     */
    public function testHeadersThatAreNotBasicCredentials(string $header)
    {
        $this->assertNull((new BasicAuth())->parse($header));
    }

    public static function badHeaders(): array
    {
        return [
            'empty' => [''],
            'bearer token' => ['Bearer abc.def'],
            'not base64' => ['Basic ***'],
            'no colon' => ['Basic ' . base64_encode('user')],
            'empty user' => ['Basic ' . base64_encode(':secret')],
            'nothing after the scheme' => ['Basic '],
        ];
    }

    public function testEmptyPasswordIsKept()
    {
        $this->assertSame(['user', ''], (new BasicAuth())->parse('Basic ' . base64_encode('user:')));
    }

    public function testMatchingCredentials()
    {
        $this->assertTrue((new BasicAuth())->matches(['user', 'secret'], 'user', 'secret'));
    }

    /**
     * @dataProvider wrongCredentials
     */
    public function testWrongCredentials(?array $given, string $user, string $password)
    {
        $this->assertFalse((new BasicAuth())->matches($given, $user, $password));
    }

    public static function wrongCredentials(): array
    {
        return [
            'nothing given' => [null, 'user', 'secret'],
            'wrong password' => [['user', 'secret2'], 'user', 'secret'],
            'wrong user' => [['user2', 'secret'], 'user', 'secret'],
            'user in another case' => [['USER', 'secret'], 'user', 'secret'],
            'no user expected' => [['', ''], '', ''],
            'no password expected' => [['user', ''], 'user', ''],
            'password with spaces around' => [['user', ' secret '], 'user', 'secret'],
            'not a pair' => [['user'], 'user', 'secret'],
        ];
    }
}
