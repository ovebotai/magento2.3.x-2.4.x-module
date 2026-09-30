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

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Ovebot\Chat\Model\Storefront\PurchaseProvider;

/**
 * Takes the orders of the success page: checkout_onepage_controller_success_action and
 * multishipping_checkout_controller_success_action, the events the Google Analytics module of Magento uses too.
 *
 * The success controller dispatches them before the page is rendered, so the purchase block finds the orders.
 */
class CheckoutSuccessAction implements ObserverInterface
{
    /**
     * @var PurchaseProvider
     */
    private $purchases;

    /**
     * @param PurchaseProvider $purchases
     */
    public function __construct(PurchaseProvider $purchases)
    {
        $this->purchases = $purchases;
    }

    /**
     * Keep the ids of the orders for the purchase block
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $orderIds = $observer->getEvent()->getData('order_ids');
        if (is_array($orderIds)) {
            $this->purchases->setOrderIds($orderIds);
        }
    }
}
