<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Storefront;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The purchases the order success page reports to Ovebot.ai, each order once.
 *
 * The orders are the ones Magento names on its own success pages, through the events
 * checkout_onepage_controller_success_action and multishipping_checkout_controller_success_action (see
 * Observer\CheckoutSuccessAction): the ids come from the checkout session of the visitor, never from the request.
 * The success page can be opened again while the session lasts, so the reported orders are kept in the checkout
 * session (the last MAX_REMEMBERED) and are not sent twice.
 *
 * One instance per request: the observer and the view model of the page share it.
 */
class PurchaseProvider
{
    public const SESSION_KEY = 'ovebot_chat_reported_orders';
    public const MAX_REMEMBERED = 10;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int[] orders of the success page
     */
    private $orderIds = [];

    /**
     * @var array|null purchases of this request, once collected
     */
    private $purchases;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
    }

    /**
     * Orders placed by the visitor, as the success page names them
     *
     * @param array $orderIds
     * @return void
     */
    public function setOrderIds(array $orderIds)
    {
        $this->orderIds = $this->cleanIds($orderIds);
        $this->purchases = null;
    }

    /**
     * Purchases not reported yet: transaction_id, total, currency, items. They count as reported from now on.
     *
     * @return array
     */
    public function getPurchases(): array
    {
        if ($this->purchases !== null) {
            return $this->purchases;
        }

        $this->purchases = [];
        if (!$this->orderIds) {
            return $this->purchases;
        }

        $reported = $this->reported();
        $new = [];
        foreach ($this->orderIds as $orderId) {
            if (in_array($orderId, $reported, true)) {
                continue;
            }

            try {
                $order = $this->orderRepository->get($orderId);
            } catch (NoSuchEntityException $e) {
                continue;
            } catch (\Exception $e) {
                // only the kind of error: the message of a database error may quote the data of the order
                $this->logger->warning('Purchase not reported (' . get_class($e) . ').');
                continue;
            }

            $this->purchases[] = [
                'transaction_id' => (int) $order->getEntityId(),
                // with taxes, in the currency the customer paid in
                'total' => round((float) $order->getGrandTotal(), 2),
                'currency' => (string) $order->getOrderCurrencyCode(),
                'items' => $this->items($order),
            ];
            $new[] = $orderId;
        }

        if ($new) {
            $this->remember(array_merge($reported, $new));
        }

        return $this->purchases;
    }

    /**
     * Lines of an order, as the feed names the products
     *
     * The item_id is the "ref" of the feed: the product id, for a variant "{parent id}-{child id}". A configurable
     * product makes two lines in an order, the parent with the price and a child with the simple product; the
     * child gives the id of the variant and is not a line of its own. The unit price has the taxes in, like the
     * feed, in the currency of the order.
     *
     * @param OrderInterface $order
     * @return array [{item_id, item_name, price, quantity}, ...]
     */
    private function items(OrderInterface $order): array
    {
        $lines = [];
        $children = [];
        foreach ((array) $order->getItems() as $item) {
            if (!$item instanceof OrderItemInterface) {
                continue;
            }
            $parentId = (int) $item->getParentItemId();
            if ($parentId > 0) {
                if (!isset($children[$parentId])) {
                    $children[$parentId] = (int) $item->getProductId();
                }
                continue;
            }
            $lines[] = $item;
        }

        $items = [];
        foreach ($lines as $item) {
            $id = (string) (int) $item->getProductId();
            $itemId = (int) $item->getItemId();
            if ($item->getProductType() === 'configurable' && isset($children[$itemId])) {
                $id .= '-' . $children[$itemId];
            }

            $items[] = [
                'item_id' => $id,
                'item_name' => (string) $item->getName(),
                'price' => round((float) $item->getPriceInclTax(), 2),
                'quantity' => (int) $item->getQtyOrdered(),
            ];
        }

        return $items;
    }

    /**
     * Orders already reported in this session
     *
     * @return int[]
     */
    private function reported(): array
    {
        $stored = $this->checkoutSession->getData(self::SESSION_KEY);

        return is_array($stored) ? $this->cleanIds($stored) : [];
    }

    /**
     * Keep the last reported orders in the session
     *
     * @param int[] $orderIds
     * @return void
     */
    private function remember(array $orderIds)
    {
        $this->checkoutSession->setData(
            self::SESSION_KEY,
            array_slice($this->cleanIds($orderIds), -self::MAX_REMEMBERED)
        );
    }

    /**
     * Keep the positive ids, once each, in their order
     *
     * @param array $ids
     * @return int[]
     */
    private function cleanIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }

        return array_values($clean);
    }
}
