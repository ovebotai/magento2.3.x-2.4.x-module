<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Ovebot\Chat\Model\StoreContext\StoreView;
use Ovebot\Chat\Model\StoreContext\StoreViewFactory;

/**
 * Gives the storefront the shop is known by at Ovebot.ai: the default store view of the installation.
 *
 * The module connects the installation as ONE shop. Its URLs, the language of the product feed and of the
 * knowledge base, and the currency are the ones of the default store view, whatever store views exist besides.
 */
class StoreContext
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var StoreViewFactory
     */
    private $storeViewFactory;

    /**
     * @var StoreView|null
     */
    private $storeView;

    /**
     * @param StoreManagerInterface $storeManager
     * @param StoreViewFactory $storeViewFactory
     */
    public function __construct(StoreManagerInterface $storeManager, StoreViewFactory $storeViewFactory)
    {
        $this->storeManager = $storeManager;
        $this->storeViewFactory = $storeViewFactory;
    }

    /**
     * The default store view
     *
     * @return StoreView
     * @throws LocalizedException when the installation has no default store view
     */
    public function get(): StoreView
    {
        if ($this->storeView === null) {
            $store = $this->storeManager->getDefaultStoreView();
            if ($store === null) {
                throw new LocalizedException(__('The default store view could not be found.'));
            }

            $this->storeView = $this->storeViewFactory->create(['store' => $store]);
        }

        return $this->storeView;
    }
}
