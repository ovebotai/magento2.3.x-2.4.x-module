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

use Ovebot\Chat\Model\StoreContext;
use Ovebot\Chat\Model\StoreEmulator;
use Psr\Log\LoggerInterface;

/**
 * The numbers of the products step of the wizard.
 *
 * The count goes through ProductSelection, as the feed does, so the number shown is the number of items the
 * feed delivers. No product data is read for it.
 */
class ProductCounter
{
    /**
     * Products read at a time; they are loaded without attributes, so the batch can be larger than in the feed
     */
    private const BATCH = 1000;

    /**
     * @var ProductSelection
     */
    private $selection;

    /**
     * @var StoreContext
     */
    private $storeContext;

    /**
     * @var StoreEmulator
     */
    private $storeEmulator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array|null|false false = not counted yet
     */
    private $counts = false;

    /**
     * @param ProductSelection $selection
     * @param StoreContext $storeContext
     * @param StoreEmulator $storeEmulator
     * @param LoggerInterface $logger
     */
    public function __construct(
        ProductSelection $selection,
        StoreContext $storeContext,
        StoreEmulator $storeEmulator,
        LoggerInterface $logger
    ) {
        $this->selection = $selection;
        $this->storeContext = $storeContext;
        $this->storeEmulator = $storeEmulator;
        $this->logger = $logger;
    }

    /**
     * Count the products. Never throws.
     *
     * @return array|null {total: enabled products of the shop, feed_count: items of the feed}; null when the
     *                    count failed
     */
    public function counts(): ?array
    {
        if ($this->counts === false) {
            try {
                // called from admin: the stock of the website is found through the current store
                $this->counts = $this->storeEmulator->run(function () {
                    $context = $this->storeContext->get();

                    return [
                        'total' => $this->selection->countEnabled($context),
                        'feed_count' => $this->selection->countItems($context, self::BATCH),
                    ];
                });
            } catch (\Exception $e) {
                $this->logger->warning('Products could not be counted: ' . $e->getMessage());
                $this->counts = null;
            }
        }

        return $this->counts;
    }
}
