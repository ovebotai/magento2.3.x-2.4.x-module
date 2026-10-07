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
use Magento\Quote\Model\Quote\Item;

/**
 * The cart of the visitor, in the shape the chat reads: {count, items}, count being the sum of the quantities.
 *
 * Each item is named as the feed names the product ("ref": the product id, for a variant "{parent id}-{child id}"),
 * with the unit price taxes included, like the feed, in the currency of the cart.
 */
class CartProvider
{
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(CheckoutSession $checkoutSession)
    {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * The cart of the visitor
     *
     * @return array {count: int, items: [{ref, sku?, name, price, currency, quantity}, ...]}
     */
    public function get(): array
    {
        $quote = $this->checkoutSession->getQuote();
        $currency = (string) $quote->getData('quote_currency_code');

        $count = 0;
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            /** @var Item $item */
            $quantity = (int) round((float) $item->getQty());
            if ($quantity <= 0) {
                continue;
            }

            $price = (float) $item->getData('price_incl_tax');
            if ($price <= 0) {
                $price = (float) $item->getPrice();
            }

            $entry = ['ref' => $this->ref($item)];
            $sku = (string) $item->getSku();
            if ($sku !== '') {
                $entry['sku'] = $sku;
            }
            $entry['name'] = (string) $item->getData('name');
            $entry['price'] = round($price, 2);
            $entry['currency'] = $currency;
            $entry['quantity'] = $quantity;

            $items[] = $entry;
            $count += $quantity;
        }

        return ['count' => $count, 'items' => $items];
    }

    /**
     * Reference of a line, as the feed gives it
     *
     * A configurable product keeps the chosen simple product in the option "simple_product" of its line.
     *
     * @param Item $item
     * @return string
     */
    private function ref(Item $item): string
    {
        $ref = (string) (int) $item->getData('product_id');
        if ($item->getProductType() === 'configurable') {
            $option = $item->getOptionByCode('simple_product');
            $childId = $option ? (int) $option->getData('product_id') : 0;
            if ($childId > 0) {
                $ref .= '-' . $childId;
            }
        }

        return $ref;
    }
}
