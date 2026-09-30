<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Api;

use GuzzleHttp\ClientFactory as GuzzleClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Ovebot\Chat\Model\Api\Exception\AuthException;
use Ovebot\Chat\Model\Api\Exception\ConnectionException;
use Ovebot\Chat\Model\Security\Random;

/**
 * Transport for Ovebot.ai: plain HTTP, with no knowledge of where the tokens are kept.
 *
 * Holds one access token in memory and talks to the account host (OAuth) and to the API host (/v1/*).
 * The life of the tokens is handled by Ovebot\Chat\Model\Integration. The client has state, so it is created
 * through its factory, never shared.
 */
class Client
{
    public const DEFAULT_ACCOUNT_HOST = 'account.ovebot.ai';
    public const DEFAULT_API_HOST = 'api.ovebot.ai';

    /**
     * The same scopes on every platform, so the grants match.
     */
    public const SCOPES = 'workspaces:read setup:widget:write setup:products:write setup:order-info:write kb:write';

    public const HOST_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\d{2,5})?\z/i';

    /**
     * Answers of the token endpoint that may say "this token is refused", when they carry an error in JSON.
     * Everything else (a redirect, a page of a firewall or of a maintenance, 404, 408, 429, 5xx) says
     * "try again later".
     */
    private const REFUSAL_STATUSES = [400, 401, 403, 422];

    /**
     * @var GuzzleClientFactory
     */
    private $guzzleFactory;

    /**
     * @var Random
     */
    private $random;

    /**
     * @var string
     */
    private $accessToken;

    /**
     * @var string
     */
    private $accountHost;

    /**
     * @var string
     */
    private $apiHost;

    /**
     * @var string
     */
    private $userAgent;

    /**
     * @var int total request timeout, in seconds
     */
    private $timeout = 30;

    /**
     * @param GuzzleClientFactory $guzzleFactory
     * @param Random $random
     * @param string $accessToken
     * @param string $accountHost empty for the default host
     * @param string $apiHost empty for the default host
     * @param string $userAgent
     */
    public function __construct(
        GuzzleClientFactory $guzzleFactory,
        Random $random,
        string $accessToken = '',
        string $accountHost = '',
        string $apiHost = '',
        string $userAgent = ''
    ) {
        $this->guzzleFactory = $guzzleFactory;
        $this->random = $random;
        $this->accessToken = $accessToken;
        $this->accountHost = $this->sanitizeHost($accountHost, self::DEFAULT_ACCOUNT_HOST);
        $this->apiHost = $this->sanitizeHost($apiHost, self::DEFAULT_API_HOST);
        $this->userAgent = $userAgent;
    }

    /**
     * Set the access token used by the API calls
     *
     * @param string $token
     * @return $this
     */
    public function setAccessToken(string $token)
    {
        $this->accessToken = $token;

        return $this;
    }

    /**
     * Set the total request timeout
     *
     * @param int $seconds
     * @return $this
     */
    public function setTimeout(int $seconds)
    {
        $this->timeout = max(1, $seconds);

        return $this;
    }

    /**
     * Host used for OAuth and registration
     *
     * @return string
     */
    public function getAccountHost(): string
    {
        return $this->accountHost;
    }

    /**
     * Host used for the API calls
     *
     * @return string
     */
    public function getApiHost(): string
    {
        return $this->apiHost;
    }

    /**
     * Random PKCE code verifier: base64url of 48 random bytes, 64 characters
     *
     * @return string
     */
    public function generateVerifier(): string
    {
        return $this->b64url($this->random->bytes(48));
    }

    /**
     * One-time OAuth state: 32 hex characters
     *
     * @return string
     */
    public function generateState(): string
    {
        return $this->random->hex(16);
    }

    /**
     * URL of the authorization page
     *
     * @param string $siteDomain host of the callback URL
     * @param string $callbackUrl
     * @param string $verifier
     * @param string $state
     * @return string
     */
    public function buildAuthUrl(string $siteDomain, string $callbackUrl, string $verifier, string $state): string
    {
        return 'https://' . $this->accountHost . '/oauth/authorize?' . http_build_query([
            'site_domain' => $siteDomain,
            'callback_url' => $callbackUrl,
            'scopes' => self::SCOPES,
            'code_challenge' => $this->b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'state' => $state,
        ]);
    }

    /**
     * URL of the registration page ("Start Free")
     *
     * @param string $plan
     * @param string $domain
     * @return string
     */
    public function buildRegisterUrl(string $plan, string $domain): string
    {
        return 'https://' . $this->accountHost . '/register?' . http_build_query([
            'plan' => $plan,
            'domain' => $domain,
        ]);
    }

    /**
     * Exchange the authorization code for tokens
     *
     * @param string $code
     * @param string $verifier
     * @return array the token endpoint body: access_token, refresh_token, expires_in, workspace, agent
     * @throws AuthException
     * @throws ConnectionException
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
        ]);
    }

    /**
     * Get a new token pair. The refresh token rotates: it can be used once.
     *
     * @param string $refreshToken
     * @return array the token endpoint body
     * @throws AuthException
     * @throws ConnectionException
     */
    public function refreshToken(string $refreshToken): array
    {
        return $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * One round trip to the API host with the current token. No refresh and no retry here.
     *
     * @param string $method
     * @param string $path for example '/v1/me'
     * @param array|null $body sent as JSON when not null
     * @return Response
     * @throws ConnectionException
     */
    public function apiRequest(string $method, string $path, ?array $body = null): Response
    {
        return $this->request(
            $method,
            'https://' . $this->apiHost . $path,
            ['Authorization' => 'Bearer ' . $this->accessToken],
            $body
        );
    }

    /**
     * Call the token endpoint
     *
     * Only a refusal raises AuthException: one of REFUSAL_STATUSES with an error in a JSON body. The caller drops
     * the tokens on it, so nothing else may look like one. Any other answer without a token raises
     * ConnectionException: it says nothing about the token, so the caller must keep it. A redirect (they are not
     * followed), the page of a firewall or of a maintenance, a 200 that is not the token answer are all of this kind.
     *
     * @param array $payload
     * @return array
     * @throws AuthException
     * @throws ConnectionException
     */
    private function tokenRequest(array $payload): array
    {
        $response = $this->request('POST', 'https://' . $this->accountHost . '/oauth/token', [], $payload);
        $status = $response->getStatus();
        $body = $response->getBody();

        if ($status === 200 && !empty($body['access_token']) && is_string($body['access_token'])) {
            return $body;
        }

        // a body that is not JSON reads as empty, so it never has these keys
        $hasError = isset($body['error']) || isset($body['message']) || isset($body['errors']);
        if (!$hasError || !in_array($status, self::REFUSAL_STATUSES, true)) {
            throw new ConnectionException(__('Ovebot.ai is temporarily unavailable (HTTP %1).', $status));
        }

        // RFC 6749 error shape: {error, error_description}; {message} is read too
        $message = isset($body['error_description']) && is_string($body['error_description'])
            ? $body['error_description']
            : '';
        if ($message === '' && isset($body['error']) && is_string($body['error'])) {
            $message = $body['error'];
        }
        if ($message === '' && isset($body['message']) && is_string($body['message'])) {
            $message = $body['message'];
        }

        throw new AuthException(
            $message !== '' ? __('%1', $message) : __('Token request failed (HTTP %1).', $status),
            null,
            $status
        );
    }

    /**
     * Send a request and decode the answer
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param array|null $body
     * @return Response
     * @throws ConnectionException when Ovebot.ai cannot be reached
     */
    private function request(string $method, string $url, array $headers, ?array $body = null): Response
    {
        $headers['Accept'] = 'application/json';
        if ($this->userAgent !== '') {
            $headers['User-Agent'] = $this->userAgent;
        }

        $options = [
            // 4xx and 5xx are read as answers, not raised as exceptions
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => min(15, $this->timeout),
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $options['body'] = (string) json_encode($body);
        }
        $options['headers'] = $headers;

        try {
            $response = $this->guzzleFactory->create()->request(strtoupper($method), $url, $options);
        } catch (GuzzleException $e) {
            throw new ConnectionException(__('Ovebot.ai connection error: %1', $e->getMessage()));
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return new Response((int) $response->getStatusCode(), is_array($decoded) ? $decoded : []);
    }

    /**
     * Accept a host override only when it is a bare host name, so nothing else gets into the URL
     *
     * @param string $host
     * @param string $default
     * @return string
     */
    private function sanitizeHost(string $host, string $default): string
    {
        $host = trim($host);

        return $host !== '' && preg_match(self::HOST_PATTERN, $host) ? $host : $default;
    }

    /**
     * Base64url without padding
     *
     * @param string $binary
     * @return string
     */
    private function b64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
