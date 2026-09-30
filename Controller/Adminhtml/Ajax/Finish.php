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
 * Wizard, "Finish setup": sends the setup to Ovebot.ai, then marks the setup as finished and switches the chat on.
 *
 * The pages were sent at step 2; what is left is the product source chosen at step 3.
 */
class Finish extends AbstractAjax
{
    /**
     * Finish the setup
     *
     * @param Integration $integration
     * @return array {success, message}; on failure {success: false, message, errors: []}
     */
    protected function handle(Integration $integration): array
    {
        if (!$integration->isConnected()) {
            return ['success' => false, 'error' => (string) __('Connect your store to Ovebot.ai first.')];
        }

        $result = $integration->finish((bool) $this->getRequest()->getParam('products_builtin'));

        if ($result['success']) {
            return ['success' => true, 'message' => (string) __('Setup complete!')];
        }

        $message = trim(__('Could not sync feed and order settings with Ovebot.ai.') . ' ' . $result['error']);

        return ['success' => false, 'message' => $message, 'errors' => [$message]];
    }
}
