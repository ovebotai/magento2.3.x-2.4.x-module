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
use Ovebot\Chat\Model\Api\Exception\OvebotException;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\IntegrationFactory;
use Ovebot\Chat\Model\KnowledgeBase\KbSync;
use Psr\Log\LoggerInterface;

/**
 * Wizard step 2, "Next": keeps the page selection and sends the ticked pages to the knowledge base.
 */
class SyncPages extends AbstractAjax
{
    /**
     * @var KbSync
     */
    private $kbSync;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param IntegrationFactory $integrationFactory
     * @param LoggerInterface $logger
     * @param KbSync $kbSync
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        IntegrationFactory $integrationFactory,
        LoggerInterface $logger,
        KbSync $kbSync
    ) {
        parent::__construct($context, $resultJsonFactory, $integrationFactory, $logger);
        $this->kbSync = $kbSync;
    }

    /**
     * Send the ticked pages
     *
     * @param Integration $integration
     * @return array {success, failed: {page id: message}, kb_limit, kb_limit_ids: [], clean}
     */
    protected function handle(Integration $integration): array
    {
        if (!$integration->isConnected()) {
            return ['success' => false, 'error' => (string) __('Connect your store to Ovebot.ai first.')];
        }

        $pageIds = $this->getPageIds();
        $integration->saveKbPageIds($pageIds);

        $result = ['failed' => [], 'kb_limit' => '', 'kb_limit_ids' => []];
        try {
            $result = $this->kbSync->sync($integration, $pageIds, true);
        } catch (OvebotException $e) {
            // the list of the agent could not be read: every page failed alike, with the same message
            foreach ($pageIds as $pageId) {
                $result['failed'][$pageId] = $e->getMessage();
            }
        }

        return [
            'success' => true,
            // an object also when empty, so the script always reads it by page id
            'failed' => (object) $result['failed'],
            'kb_limit' => $result['kb_limit'],
            'kb_limit_ids' => array_values($result['kb_limit_ids']),
            'clean' => !$result['failed'] && !$result['kb_limit_ids'],
        ];
    }

    /**
     * Page ids of the request: positive numbers, once each
     *
     * @return int[]
     */
    private function getPageIds(): array
    {
        $given = $this->getRequest()->getParam('page_ids', []);

        $pageIds = [];
        foreach (is_array($given) ? $given : [] as $pageId) {
            if (is_scalar($pageId) && (int) $pageId > 0) {
                $pageIds[(int) $pageId] = (int) $pageId;
            }
        }

        return array_values($pageIds);
    }
}
