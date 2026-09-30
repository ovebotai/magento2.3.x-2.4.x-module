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
 * Settings page, "Regenerate credentials": a new user and password for the order endpoint.
 */
class RegenOrderCreds extends AbstractAjax
{
    /**
     * Replace the credentials of the order endpoint
     *
     * @param Integration $integration
     * @return array {success, user, pass, message}; on failure {success: false, message}
     */
    protected function handle(Integration $integration): array
    {
        $result = $integration->regenerateOrderCreds();

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => trim(
                    __('Could not sync with Ovebot.ai - credentials left unchanged.') . ' ' . $result['error']
                ),
            ];
        }

        return [
            'success' => true,
            'user' => $result['user'],
            'pass' => $result['pass'],
            'message' => (string) ($result['synced']
                ? __('Credentials regenerated and synced with Ovebot.ai.')
                : __('Credentials regenerated.')),
        ];
    }
}
