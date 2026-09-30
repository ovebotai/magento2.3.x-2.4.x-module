<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model;

use Ovebot\Chat\Model\ResourceModel\OauthState as OauthStateResource;

/**
 * Keeps the authorizations in progress. The state is one-time: the callback takes it out and the PKCE verifier
 * with it.
 */
class OauthStateStorage
{
    public const TTL = 900;
    public const MAX_PENDING = 5;

    // \z, not $: in PHP "$" also matches before a trailing line break
    private const STATE_PATTERN = '/^[a-f0-9]{32}\z/';

    /**
     * @var OauthStateResource
     */
    private $resource;

    /**
     * @param OauthStateResource $resource
     */
    public function __construct(OauthStateResource $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Remember an authorization that is starting
     *
     * Several authorizations may be in progress, so a second click on Connect does not break the first one.
     *
     * @param string $state
     * @param string $verifier
     * @param string $returnUrl absolute admin URL
     * @param int $adminUserId
     * @param int $now unix time
     * @return void
     */
    public function add(string $state, string $verifier, string $returnUrl, int $adminUserId, int $now)
    {
        $this->resource->deleteExpired($now);
        $this->resource->insertState([
            'state' => $state,
            'verifier' => $verifier,
            'return_url' => $returnUrl,
            'admin_user_id' => $adminUserId,
            'expires_at' => $now + self::TTL,
        ]);
        $this->resource->trim(self::MAX_PENDING);
    }

    /**
     * Take an authorization out: the entry is removed whether it is still valid or not
     *
     * @param string $state
     * @param int $now unix time
     * @return array|null verifier, return_url, admin_user_id; null when unknown or expired
     */
    public function pull(string $state, int $now): ?array
    {
        if (!preg_match(self::STATE_PATTERN, $state)) {
            return null;
        }

        $row = $this->resource->fetchState($state);
        // only the request that removed the row may use it
        if ($row === null || $this->resource->deleteState($state) !== 1) {
            return null;
        }

        if (!isset($row['verifier'], $row['return_url'], $row['expires_at'])
            || (int) $row['expires_at'] < $now
            || (string) $row['verifier'] === ''
        ) {
            return null;
        }

        return [
            'verifier' => (string) $row['verifier'],
            'return_url' => (string) $row['return_url'],
            'admin_user_id' => isset($row['admin_user_id']) ? (int) $row['admin_user_id'] : 0,
        ];
    }
}
