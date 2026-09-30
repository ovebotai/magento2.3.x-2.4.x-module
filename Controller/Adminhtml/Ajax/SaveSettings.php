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

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\IntegrationFactory;
use Ovebot\Chat\Model\Widget\Settings as WidgetSettings;
use Psr\Log\LoggerInterface;

/**
 * Settings page, "Save settings": the chat switch, the three switches mirrored by the account, the appearance.
 */
class SaveSettings extends AbstractAjax
{
    /**
     * @var WidgetSettings
     */
    private $widgetSettings;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param IntegrationFactory $integrationFactory
     * @param LoggerInterface $logger
     * @param WidgetSettings $widgetSettings
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        IntegrationFactory $integrationFactory,
        LoggerInterface $logger,
        WidgetSettings $widgetSettings
    ) {
        parent::__construct($context, $resultJsonFactory, $integrationFactory, $logger);
        $this->widgetSettings = $widgetSettings;
    }

    /**
     * Save the settings
     *
     * @param Integration $integration
     * @return array {success, message, effective, warnings?, needs_reconnect?}; on failure {success: false, error}
     */
    protected function handle(Integration $integration): array
    {
        if (!$integration->getConnection()->isSetupComplete()) {
            // the chat is switched on by "Finish setup", not from here
            return ['success' => false, 'error' => (string) __('Finish the setup first.')];
        }

        $request = $this->getRequest();

        $raw = [];
        foreach (array_keys($this->widgetSettings->defaults()) as $key) {
            $raw[$key] = $request->getParam('widget_' . $key, '');
        }

        $result = $integration->saveSettings(
            (bool) $request->getParam('chat_status'),
            $this->widgetSettings->sanitize($raw),
            (bool) $request->getParam('products_builtin'),
            (bool) $request->getParam('products_recommend'),
            (bool) $request->getParam('order_enabled')
        );

        switch ($result['status']) {
            case Integration::SAVE_FAILED:
                return ['success' => false, 'error' => $result['error']];
            case Integration::SAVE_NEEDS_RECONNECT:
                return [
                    'success' => true,
                    'needs_reconnect' => true,
                    'message' => (string) __(
                        'Chat settings saved. Reconnect to Ovebot.ai to sync feed and order settings.'
                    ),
                    'effective' => $result['effective'],
                ];
            case Integration::SAVE_SYNC_FAILED:
                return [
                    'success' => true,
                    'message' => (string) __('Settings saved.'),
                    'warnings' => [trim(
                        __('Settings saved locally but could not sync with Ovebot.ai.') . ' ' . $result['error']
                    )],
                    'effective' => $result['effective'],
                ];
            default:
                return [
                    'success' => true,
                    'message' => (string) __('Settings saved.'),
                    'effective' => $result['effective'],
                ];
        }
    }
}
