<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Cache;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContextFactory;
use Psr\Log\LoggerInterface;

/**
 * Removes the cached storefront pages that carry the widget, so a change of the widget is seen at once.
 *
 * The widget block gives every page it is on the tag TAG. Cleaning the tag goes through the event
 * clean_cache_by_tags, which the built-in full page cache (Magento_PageCache) and Varnish (Magento_CacheInvalidate)
 * both listen to, the same way the indexers of Magento_Catalog clean their pages. The widget is on every page, so
 * this empties the full page cache: it is called only when what the widget shows really changed.
 */
class WidgetCache
{
    public const TAG = 'ovebot_chat_widget';

    /**
     * @var CacheContextFactory
     */
    private $cacheContextFactory;

    /**
     * @var ManagerInterface
     */
    private $eventManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CacheContextFactory $cacheContextFactory
     * @param ManagerInterface $eventManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        CacheContextFactory $cacheContextFactory,
        ManagerInterface $eventManager,
        LoggerInterface $logger
    ) {
        $this->cacheContextFactory = $cacheContextFactory;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
    }

    /**
     * Remove the cached pages that carry the widget. Never throws.
     *
     * @return bool false when the cache could not be cleaned
     */
    public function clean(): bool
    {
        try {
            // a context of its own: the shared one collects the tags of the indexers
            $context = $this->cacheContextFactory->create();
            $context->registerTags([self::TAG]);
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $context]);
        } catch (\Exception $e) {
            // the change is saved; the pages show it when their cache expires
            $this->logger->warning('Widget cache not cleaned (' . get_class($e) . ').');

            return false;
        }

        return true;
    }
}
