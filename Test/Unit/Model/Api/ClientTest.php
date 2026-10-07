<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Api;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientFactory as GuzzleClientFactory;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Ovebot\Chat\Model\Api\Client;
use Ovebot\Chat\Model\Api\Exception\AuthException;
use Ovebot\Chat\Model\Api\Exception\ConnectionException;
use Ovebot\Chat\Model\Security\Random;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    /**
     * @var array requests sent through the transport: [method, url, options]
     */
    private $sent = [];

    /**
     * @param array $answers GuzzleResponse or exception, in the order they are returned
     * @param string $accountHost
     * @param string $apiHost
     * @param string $token
     * @return Client
     */
    private function client(
        array $answers = [],
        string $accountHost = '',
        string $apiHost = '',
        string $token = 'AT1'
    ): Client {
        $this->sent = [];

        $guzzle = $this->createMock(GuzzleClient::class);
        $guzzle->method('request')->willReturnCallback(
            function ($method, $url, $options) use (&$answers) {
                $this->sent[] = [$method, $url, $options];
                $answer = array_shift($answers);
                if ($answer instanceof \Exception) {
                    throw $answer;
                }

                return $answer ?: new GuzzleResponse(200, [], '{}');
            }
        );

        $factory = $this->getMockBuilder(GuzzleClientFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($guzzle);

        return new Client($factory, new Random(), $token, $accountHost, $apiHost, 'OvebotAI-Magento/1.0.0 (test)');
    }

    /**
     * @dataProvider hosts
     */
    public function testHostOverrideAcceptsOnlyBareHostNames(string $given, string $expected)
    {
        $client = $this->client([], $given, $given);

        $this->assertSame($expected !== '' ? $expected : Client::DEFAULT_ACCOUNT_HOST, $client->getAccountHost());
        $this->assertSame($expected !== '' ? $expected : Client::DEFAULT_API_HOST, $client->getApiHost());
    }

    public static function hosts(): array
    {
        return [
            'empty' => ['', ''],
            'host' => ['account.staging.ovebot.ai', 'account.staging.ovebot.ai'],
            'host and port' => ['api.staging.ovebot.ai:8443', 'api.staging.ovebot.ai:8443'],
            'spaces around' => ['  localhost  ', 'localhost'],
            'path' => ['evil.example/../x', ''],
            'scheme' => ['https://evil.example', ''],
            'credentials' => ['user@evil.example', ''],
            'query' => ['evil.example?x=1', ''],
            'space inside' => ['evil .example', ''],
            'bad port' => ['evil.example:1', ''],
            'leading dash' => ['-evil.example', ''],
        ];
    }

    public function testAuthorizeUrl()
    {
        $url = $this->client()->buildAuthUrl('shop.ro', 'https://shop.ro/ovebot/oauth/callback/', 'verifier', 'state1');

        $this->assertStringStartsWith('https://account.ovebot.ai/oauth/authorize?', $url);

        $query = [];
        parse_str((string) substr($url, strpos($url, '?') + 1), $query);
        $this->assertSame(
            [
                'site_domain' => 'shop.ro',
                'callback_url' => 'https://shop.ro/ovebot/oauth/callback/',
                'scopes' => Client::SCOPES,
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'verifier', true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
                'state' => 'state1',
            ],
            $query
        );
    }

    public function testCodeChallengeMatchesTheRfcExample()
    {
        // RFC 7636, appendix B
        $url = $this->client()->buildAuthUrl('s', 'c', 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'x');

        $this->assertStringContainsString('code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&', $url);
    }

    public function testRegisterUrl()
    {
        $this->assertSame(
            'https://account.ovebot.ai/register?domain=shop.ro',
            $this->client()->buildRegisterUrl('shop.ro')
        );
    }

    public function testVerifierAndState()
    {
        $client = $this->client();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{64}$/', $client->generateVerifier());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $client->generateState());
        $this->assertNotSame($client->generateState(), $client->generateState());
    }

    public function testApiRequestSendsTokenAndJson()
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"integration":{"products":true}}')]);

        $response = $client->apiRequest(
            'put',
            '/v1/workspaces/w/agents/default/setup',
            ['widget' => ['language' => 'ro']]
        );

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->isSuccess());
        $this->assertSame(['integration' => ['products' => true]], $response->getBody());

        list($method, $url, $options) = $this->sent[0];
        $this->assertSame('PUT', $method);
        $this->assertSame('https://api.ovebot.ai/v1/workspaces/w/agents/default/setup', $url);
        $this->assertSame('Bearer AT1', $options['headers']['Authorization']);
        $this->assertSame('application/json', $options['headers']['Accept']);
        $this->assertSame('application/json', $options['headers']['Content-Type']);
        $this->assertSame('OvebotAI-Magento/1.0.0 (test)', $options['headers']['User-Agent']);
        $this->assertSame('{"widget":{"language":"ro"}}', $options['body']);
        $this->assertFalse($options['http_errors']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(30, $options['timeout']);
        $this->assertSame(15, $options['connect_timeout']);
    }

    public function testRequestWithoutBodyAndNewToken()
    {
        $client = $this->client([new GuzzleResponse(404, [], '<html>Not found</html>')]);
        $client->setAccessToken('AT2')->setTimeout(5);

        $response = $client->apiRequest('GET', '/v1/me');

        // a body that is not JSON reads as empty
        $this->assertSame(404, $response->getStatus());
        $this->assertFalse($response->isSuccess());
        $this->assertSame([], $response->getBody());

        $options = $this->sent[0][2];
        $this->assertSame('Bearer AT2', $options['headers']['Authorization']);
        $this->assertArrayNotHasKey('body', $options);
        $this->assertArrayNotHasKey('Content-Type', $options['headers']);
        $this->assertSame(5, $options['timeout']);
        $this->assertSame(5, $options['connect_timeout']);
    }

    public function testTransportFailureIsAConnectionException()
    {
        $client = $this->client([new ConnectException('timed out', new Request('GET', 'https://api.ovebot.ai'))]);

        $this->expectException(ConnectionException::class);
        $client->apiRequest('GET', '/v1/me');
    }

    public function testExchangeCode()
    {
        $body = '{"access_token":"AT","refresh_token":"RT","expires_in":3600,'
            . '"workspace":{"slug":"my-shop"},"agent":null}';
        $client = $this->client([new GuzzleResponse(200, [], $body)], 'account.staging.test');

        $tokens = $client->exchangeCode('the-code', 'the-verifier');

        $this->assertSame('AT', $tokens['access_token']);
        $this->assertSame('my-shop', $tokens['workspace']['slug']);

        list($method, $url, $options) = $this->sent[0];
        $this->assertSame('POST', $method);
        $this->assertSame('https://account.staging.test/oauth/token', $url);
        $this->assertArrayNotHasKey('Authorization', $options['headers']);
        $this->assertSame(
            ['grant_type' => 'authorization_code', 'code' => 'the-code', 'code_verifier' => 'the-verifier'],
            json_decode($options['body'], true)
        );
    }

    public function testRefreshTokenPayload()
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"access_token":"AT2","refresh_token":"RT2"}')]);

        $this->assertSame('RT2', $client->refreshToken('RT1')['refresh_token']);
        $this->assertSame(
            ['grant_type' => 'refresh_token', 'refresh_token' => 'RT1'],
            json_decode($this->sent[0][2]['body'], true)
        );
    }

    /**
     * @dataProvider refusals
     */
    public function testTokenRefusalIsAnAuthException(int $status, string $body, string $message)
    {
        $client = $this->client([new GuzzleResponse($status, [], $body)]);

        try {
            $client->refreshToken('RT1');
            $this->fail('AuthException expected');
        } catch (AuthException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($status, $e->getCode());
        }
    }

    public static function refusals(): array
    {
        return [
            'description' => [400, '{"error":"invalid_grant","error_description":"Token revoked"}', 'Token revoked'],
            'error only' => [401, '{"error":"invalid_grant"}', 'invalid_grant'],
            'message only' => [401, '{"message":"Unauthenticated."}', 'Unauthenticated.'],
            'forbidden in JSON' => [403, '{"error":"access_denied"}', 'access_denied'],
            'error as an object' => [400, '{"error":{"code":"revoked"}}', 'Token request failed (HTTP 400).'],
            'field errors' => [422, '{"errors":{"refresh_token":["Invalid."]}}', 'Token request failed (HTTP 422).'],
            'text with placeholders' => [400, '{"error":"bad %1 value"}', 'bad %1 value'],
        ];
    }

    /**
     * @dataProvider unavailable
     */
    public function testTokenEndpointUnavailableIsNotARefusal(int $status, string $body)
    {
        // the refresh token must be kept: the answer says nothing about it
        $client = $this->client([new GuzzleResponse($status, [], $body)]);

        try {
            $client->refreshToken('RT1');
            $this->fail('ConnectionException expected');
        } catch (ConnectionException $e) {
            $this->assertSame('Ovebot.ai is temporarily unavailable (HTTP ' . $status . ').', $e->getMessage());
        }
    }

    public static function unavailable(): array
    {
        $error = '{"error":"server_error"}';

        return [
            '500' => [500, $error],
            '502' => [502, $error],
            '503' => [503, $error],
            '504' => [504, $error],
            '408' => [408, $error],
            '429' => [429, $error],
            // nothing below says "this token is refused", whatever the status
            'redirect to a maintenance page' => [302, ''],
            'page of a firewall' => [403, '<html><body>Access denied</body></html>'],
            'empty 401' => [401, ''],
            'empty 400' => [400, ''],
            'route not found' => [404, '{"message":"Not Found"}'],
            'method not allowed' => [405, '{"message":"Method Not Allowed"}'],
            '200 with a page' => [200, '<html><body>Please wait</body></html>'],
            '200 without a token' => [200, '{"refresh_token":"RT"}'],
            '200 with an error' => [200, '{"error":"invalid_grant"}'],
        ];
    }
}
