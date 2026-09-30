<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\KnowledgeBase;

use Magento\Cms\Api\Data\PageInterface;
use Ovebot\Chat\Model\IntegrationFactory;
use Psr\Log\LoggerInterface;

/**
 * Keeps the knowledge base in step with the CMS pages edited or deleted in Magento.
 *
 * Runs only for a page that is in the selection of the wizard. Errors are logged and never raised: saving a
 * CMS page must not fail because Ovebot.ai cannot be reached.
 */
class CmsResync
{
    /**
     * Seconds a call may take; the merchant is waiting for the page to save
     */
    public const TIMEOUT = 10;

    /**
     * @var IntegrationFactory
     */
    private $integrationFactory;

    /**
     * @var KbSync
     */
    private $kbSync;

    /**
     * @var CmsPageProvider
     */
    private $pages;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param IntegrationFactory $integrationFactory
     * @param KbSync $kbSync
     * @param CmsPageProvider $pages
     * @param LoggerInterface $logger
     */
    public function __construct(
        IntegrationFactory $integrationFactory,
        KbSync $kbSync,
        CmsPageProvider $pages,
        LoggerInterface $logger
    ) {
        $this->integrationFactory = $integrationFactory;
        $this->kbSync = $kbSync;
        $this->pages = $pages;
        $this->logger = $logger;
    }

    /**
     * A page was saved: bring its entry up to date
     *
     * The entry is switched off when the page was disabled or moved away from the default store view.
     *
     * @param PageInterface $page
     * @return void
     */
    public function onSaved(PageInterface $page)
    {
        $pageId = (int) $page->getId();

        try {
            $integration = $this->integrationFactory->create();
            if (!in_array($pageId, $integration->getKbPageIds(), true) || !$integration->isSetupComplete()) {
                return;
            }
            $integration->setTimeout(self::TIMEOUT);

            if (!$this->pages->isAssigned($pageId)) {
                $this->kbSync->deactivate($integration, $pageId, (string) $page->getTitle());

                return;
            }

            $result = $this->kbSync->sync($integration, [$pageId], (bool) $page->isActive());
            foreach ($result['failed'] as $message) {
                $this->log($pageId, 'resync skipped: ' . $message);
            }
            if ($result['kb_limit'] !== '') {
                $this->log($pageId, 'resync stopped by the knowledge base quota: ' . $result['kb_limit']);
            }
        } catch (\Throwable $e) {
            $this->log($pageId, 'resync failed: ' . $e->getMessage());
        }
    }

    /**
     * A page was deleted: forget it in the selection and switch its entry off
     *
     * @param PageInterface $page the deleted page; it still holds its title and content
     * @return void
     */
    public function onDeleted(PageInterface $page)
    {
        $pageId = (int) $page->getId();

        try {
            $integration = $this->integrationFactory->create();
            if (!in_array($pageId, $integration->getKbPageIds(), true)) {
                return;
            }
            $integration->saveKbPageIds(array_diff($integration->getKbPageIds(), [$pageId]));

            if (!$integration->isSetupComplete()) {
                return;
            }
            $integration->setTimeout(self::TIMEOUT);

            $this->kbSync->deactivate($integration, $pageId, (string) $page->getTitle());
        } catch (\Throwable $e) {
            $this->log($pageId, 'deactivation after delete failed: ' . $e->getMessage());
        }
    }

    /**
     * Write a warning in the module log
     *
     * @param int $pageId
     * @param string $message
     * @return void
     */
    private function log(int $pageId, string $message)
    {
        $this->logger->warning('CMS page #' . $pageId . ' ' . $message);
    }
}
