<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Controller\Adminhtml\Ajax;

use Ovebot\Chat\Model\Integration;

/**
 * Settings page, "Regenerate hash": a new feed URL; the old one stops working.
 */
class RegenFeedHash extends AbstractAjax
{
    /**
     * Replace the feed hash
     *
     * @param Integration $integration
     * @return array {success, hash, url, message}; on failure {success: false, message}
     */
    protected function handle(Integration $integration): array
    {
        $result = $integration->regenerateFeedHash();

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => trim(
                    __('Could not sync with Ovebot.ai - feed hash left unchanged.') . ' ' . $result['error']
                ),
            ];
        }

        return [
            'success' => true,
            'hash' => $result['hash'],
            'url' => $result['url'],
            'message' => (string) ($result['synced']
                ? __('Feed URL regenerated and synced with Ovebot.ai.')
                : __('Feed URL regenerated.')),
        ];
    }
}
