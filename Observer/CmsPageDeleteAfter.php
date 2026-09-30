<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Observer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Ovebot\Chat\Model\KnowledgeBase\CmsResync;
use Psr\Log\LoggerInterface;

/**
 * Event cms_page_delete_commit_after: the page is gone, its knowledge base entries are switched off.
 */
class CmsPageDeleteAfter implements ObserverInterface
{
    /**
     * @var CmsResync
     */
    private $cmsResync;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CmsResync $cmsResync
     * @param LoggerInterface $logger
     */
    public function __construct(CmsResync $cmsResync, LoggerInterface $logger)
    {
        $this->cmsResync = $cmsResync;
        $this->logger = $logger;
    }

    /**
     * Switch off the knowledge base entries of the deleted page
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $page = $observer->getEvent()->getData('data_object');
        if (!$page instanceof PageInterface || !$page->getId()) {
            return;
        }

        try {
            $this->cmsResync->onDeleted($page);
        } catch (\Throwable $e) {
            // deleting a CMS page must never fail because of Ovebot.ai
            $this->logger->warning(
                'CMS page #' . (int) $page->getId() . ' deactivation after delete failed: ' . $e->getMessage()
            );
        }
    }
}
