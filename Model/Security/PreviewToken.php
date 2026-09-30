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

use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\ResourceModel\Connection as ConnectionResource;

/**
 * Token of the chat preview: "Chat with the AI agent" in the admin opens the storefront with the chat open.
 *
 * The storefront pages come from the full page cache, so the page cannot check the token when it is written.
 * widget.js sends the token to ovebot/preview/validate, which answers without cache; only a valid token opens the
 * chat by itself, so a link made up by someone else cannot open it for the visitors of the shop. The shop keeps one
 * token at a time.
 */
class PreviewToken
{
    /**
     * Lifetime of a token, in seconds
     */
    public const TTL = 3600;

    // \z, not $: in PHP "$" also matches before a trailing line break
    public const PATTERN = '/^[a-f0-9]{32}\z/';

    /**
     * @var ConnectionRepository
     */
    private $connections;

    /**
     * @var ConnectionResource
     */
    private $resource;

    /**
     * @var Random
     */
    private $random;

    /**
     * @param ConnectionRepository $connections
     * @param ConnectionResource $resource
     * @param Random $random
     */
    public function __construct(ConnectionRepository $connections, ConnectionResource $resource, Random $random)
    {
        $this->connections = $connections;
        $this->resource = $resource;
        $this->random = $random;
    }

    /**
     * Make a new token and keep it; the previous one stops working
     *
     * @param int $now unix time
     * @return string empty when the shop has no saved connection
     * @throws \Exception when no random value can be made or the token cannot be written
     */
    public function issue(int $now): string
    {
        $connection = $this->connections->get();
        $id = (int) $connection->getId();
        if ($id <= 0) {
            return '';
        }

        $token = $this->random->hex(16);
        $expires = $now + self::TTL;
        $this->resource->savePreviewToken($id, $token, $expires);
        $connection->setPreviewToken($token, $expires);

        return $token;
    }

    /**
     * Whether a token is the one of the shop and has not expired. Never throws.
     *
     * @param string $token
     * @param int $now unix time
     * @return bool
     */
    public function isValid(string $token, int $now): bool
    {
        if (!preg_match(self::PATTERN, $token)) {
            return false;
        }

        try {
            $stored = $this->connections->get()->getPreviewToken($now);
        } catch (\Exception $e) {
            return false;
        }

        return $stored !== '' && hash_equals($stored, $token);
    }
}
