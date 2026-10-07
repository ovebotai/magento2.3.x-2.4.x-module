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

use Ovebot\Chat\Model\StoreContext\StoreView;

/**
 * Builds the body of PUT /v1/workspaces/{workspace}/agents/{agent}/setup.
 *
 * The overrides swap values in WITHOUT storing them first, so a refused write keeps the old configuration, which
 * still works: a new feed hash, new credentials or a changed switch are stored only once Ovebot.ai accepted them.
 *
 * Override keys: products_recommend, products_builtin, order_enabled, add_to_cart (bool), feed_hash, order_user,
 * order_pass (string).
 */
class SetupPayloadBuilder
{
    public const DEFAULT_LANGUAGE = 'auto';
    public const LOOKUP_METHOD = 'email';

    /**
     * Build the three sections of the setup: widget, order_info, products
     *
     * @param Connection $connection
     * @param StoreView $storeView the storefront of the shop, which gives the URLs and the currency
     * @param array $overrides
     * @return array
     */
    public function build(Connection $connection, StoreView $storeView, array $overrides = []): array
    {
        $recommend = $this->flag($overrides, 'products_recommend', $connection->isProductsRecommend());
        $builtin = $this->flag($overrides, 'products_builtin', $connection->isProductsBuiltin());
        $orders = $this->flag($overrides, 'order_enabled', $connection->isOrderEnabled());

        // "enabled" mirrors the recommendations switch of the account and is always sent. A feed URL makes the
        // account import the feed again and switch the recommendations back ON, so the URL and the currency go
        // out only while the recommendations are on AND the built-in feed is the source. With a feed of the
        // merchant only "enabled" is sent, and the account keeps the merchant's feed URL.
        $products = [
            'enabled' => $recommend,
            // mirrors the "Add to cart" switch of the shop and is always sent: the storefront defines the
            // add-to-cart function only while it is on
            'add_to_cart' => $this->flag($overrides, 'add_to_cart', $connection->isAddToCart()),
        ];
        if ($recommend && $builtin) {
            $products['feed_url'] = $storeView->getFeedUrl(
                $this->text($overrides, 'feed_hash', $connection->getFeedHash())
            );
            $products['currency'] = $storeView->getCurrencyCode();
        }

        return [
            // this section takes the language only, and a widget object without it is refused with a 422
            'widget' => ['language' => $this->language($connection)],
            // validated as a whole: the URL and the lookup method are mandatory. The method is always "email",
            // although the endpoint also takes a phone number.
            'order_info' => [
                'enabled' => $orders,
                'api_url' => $storeView->getOrdersUrl(),
                'api_user' => $this->text($overrides, 'order_user', $connection->getOrderUser()),
                'api_password' => $this->text($overrides, 'order_pass', $connection->getOrderPass()),
                'lookup_method' => self::LOOKUP_METHOD,
            ],
            'products' => $products,
        ];
    }

    /**
     * Language of the widget; not set means the language of the browser
     *
     * @param Connection $connection
     * @return string
     */
    private function language(Connection $connection): string
    {
        $widget = $connection->getWidget();
        $language = isset($widget['language']) && is_scalar($widget['language']) ? (string) $widget['language'] : '';

        return $language !== '' ? $language : self::DEFAULT_LANGUAGE;
    }

    /**
     * A switch: the override when given, the stored value otherwise
     *
     * @param array $overrides
     * @param string $key
     * @param bool $stored
     * @return bool
     */
    private function flag(array $overrides, string $key, bool $stored): bool
    {
        return array_key_exists($key, $overrides) ? (bool) $overrides[$key] : $stored;
    }

    /**
     * A text value: the override when given, the stored value otherwise
     *
     * @param array $overrides
     * @param string $key
     * @param string $stored
     * @return string
     */
    private function text(array $overrides, string $key, string $stored): string
    {
        return array_key_exists($key, $overrides) && is_scalar($overrides[$key]) ? (string) $overrides[$key] : $stored;
    }
}
