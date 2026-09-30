<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Feed;

use Ovebot\Chat\Model\Connection;

/**
 * Decides whether a request may read the product feed.
 */
class FeedAccess
{
    // \z, not $: in PHP "$" also matches before a trailing line break
    private const HASH_PATTERN = '/^[a-f0-9]{32}\z/';

    /**
     * Whether the feed is open for the hash of the request
     *
     * The chat is the main switch. With a feed of the merchant's own or with the recommendations switched off,
     * Ovebot.ai does not read the catalog from here, so the feed stays closed.
     *
     * @param Connection $connection
     * @param string $given hash of the request
     * @return bool
     */
    public function isAllowed(Connection $connection, string $given): bool
    {
        $hash = $connection->getFeedHash();

        return preg_match(self::HASH_PATTERN, $given) === 1
            && $hash !== ''
            && hash_equals($hash, $given)
            && $connection->isChatEnabled()
            && $connection->isProductsBuiltin()
            && $connection->isProductsRecommend();
    }
}
