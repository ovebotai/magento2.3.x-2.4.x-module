<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Security;

use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * HTTP Basic credentials of the order lookup endpoint.
 *
 * PHP fills PHP_AUTH_USER / PHP_AUTH_PW only under mod_php; under PHP-FPM or CGI the Authorization header is read
 * instead. On Apache it reaches PHP only with `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` or
 * `CGIPassAuth On`, and after a rewrite it may arrive as REDIRECT_HTTP_AUTHORIZATION.
 */
class BasicAuth
{
    /**
     * User and password sent with the request
     *
     * @param HttpRequest $request
     * @return string[]|null [user, password]; null when the request carries none
     */
    public function credentials(HttpRequest $request): ?array
    {
        $user = $this->scalar($request->getServer('PHP_AUTH_USER'));
        if ($user !== '') {
            return [$user, $this->scalar($request->getServer('PHP_AUTH_PW'))];
        }

        $header = $this->scalar($request->getHeader('Authorization'));
        if ($header === '') {
            $header = $this->scalar($request->getServer('REDIRECT_HTTP_AUTHORIZATION'));
        }

        return $this->parse($header);
    }

    /**
     * Credentials of an Authorization header
     *
     * @param string $header
     * @return string[]|null [user, password]
     */
    public function parse(string $header): ?array
    {
        $header = trim($header);
        if (stripos($header, 'Basic ') !== 0) {
            return null;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- decoding the header is what Basic auth is
        $decoded = base64_decode(trim(substr($header, 6)), true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return null;
        }

        list($user, $password) = explode(':', $decoded, 2);

        return $user !== '' ? [$user, $password] : null;
    }

    /**
     * Whether the credentials given are the expected ones; compared in constant time
     *
     * @param string[]|null $given result of credentials()
     * @param string $expectedUser
     * @param string $expectedPassword
     * @return bool false as well when nothing is expected
     */
    public function matches(?array $given, string $expectedUser, string $expectedPassword): bool
    {
        $expectedUser = trim($expectedUser);
        $expectedPassword = trim($expectedPassword);
        if ($given === null || count($given) !== 2 || $expectedUser === '' || $expectedPassword === '') {
            return false;
        }

        list($user, $password) = array_values($given);
        $userMatches = hash_equals($expectedUser, (string) $user);
        $passwordMatches = hash_equals($expectedPassword, (string) $password);

        return $userMatches && $passwordMatches;
    }

    /**
     * A server value or header as a string
     *
     * @param mixed $value
     * @return string
     */
    private function scalar($value): string
    {
        return is_string($value) ? $value : '';
    }
}
