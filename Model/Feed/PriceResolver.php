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

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Ovebot\Chat\Model\StoreContext\StoreView;

/**
 * Prices of the feed: the price the visitor sees, with tax, in the currency the storefront displays.
 *
 * The amounts come from the price index, read by the product collection for the visitor who is not logged in.
 */
class PriceResolver
{
    /**
     * Types sold as a set: the feed quotes the lowest price they can be bought for
     */
    private const COMPOSITE_TYPES = ['bundle', 'grouped'];

    /**
     * @var CatalogHelper
     */
    private $catalogHelper;

    /**
     * Amount the tax of a tax class is measured on
     */
    private const TAX_SAMPLE = 100.0;

    /**
     * @var array|null {code: string, rate: float}, once read
     */
    private $currency;

    /**
     * @var float[] tax class id => factor
     */
    private $taxFactors = [];

    /**
     * @param CatalogHelper $catalogHelper
     */
    public function __construct(CatalogHelper $catalogHelper)
    {
        $this->catalogHelper = $catalogHelper;
    }

    /**
     * Currency of the prices: the display currency, or the base currency when there is no rate between them
     *
     * @param StoreView $context
     * @return string
     */
    public function getCurrencyCode(StoreView $context): string
    {
        return $this->currency($context)['code'];
    }

    /**
     * Prices of a product loaded with the price index
     *
     * @param Product $product
     * @param StoreView $context
     * @return array|null {price: float, special: float|null}; null when the price comes out as zero
     */
    public function resolve(Product $product, StoreView $context): ?array
    {
        $amounts = $this->pick(
            (string) $product->getTypeId(),
            (float) $product->getData('price'),
            (float) $product->getData('final_price'),
            (float) $product->getData('min_price')
        );

        $price = $this->display($product, $amounts['price'], $context);
        if ($price <= 0) {
            return null;
        }

        $special = $amounts['final'] < $amounts['price']
            ? $this->display($product, $amounts['final'], $context)
            : $price;

        return ['price' => $price, 'special' => $special > 0 && $special < $price ? $special : null];
    }

    /**
     * Choose the regular and the final amount from the columns of the price index
     *
     * A type without a price of its own has only the lowest price filled in.
     *
     * @param string $type product type
     * @param float $price regular price
     * @param float $final price after the special price and the catalog rules
     * @param float $min lowest price the product can be bought for
     * @return array {price: float, final: float}
     */
    public function pick(string $type, float $price, float $final, float $min): array
    {
        $composite = in_array($type, self::COMPOSITE_TYPES, true);

        $regular = !$composite && $price > 0 ? $price : $min;
        $reduced = !$composite && $final > 0 ? $final : $min;

        return ['price' => max($regular, $reduced), 'final' => $reduced];
    }

    /**
     * Amount as the storefront shows it: tax included, display currency, two decimals
     *
     * @param Product $product
     * @param float $amount
     * @param StoreView $context
     * @return float
     */
    private function display(Product $product, float $amount, StoreView $context): float
    {
        return round($amount * $this->taxFactor($product, $context) * $this->currency($context)['rate'], 2);
    }

    /**
     * What an amount of the price index is multiplied by to get the price with tax
     *
     * The tax is a share of the price, the same for every product of a tax class. It is asked from Magento once
     * for each class, on a sample amount, instead of twice for each item of the feed.
     *
     * @param Product $product
     * @param StoreView $context
     * @return float
     */
    private function taxFactor(Product $product, StoreView $context): float
    {
        $taxClass = (string) $product->getData('tax_class_id');

        if (!isset($this->taxFactors[$taxClass])) {
            $withTax = (float) $this->catalogHelper->getTaxPrice(
                $product,
                self::TAX_SAMPLE,
                true,
                null,
                null,
                null,
                $context->getStore(),
                null,
                false
            );
            $this->taxFactors[$taxClass] = $withTax > 0 ? $withTax / self::TAX_SAMPLE : 1.0;
        }

        return $this->taxFactors[$taxClass];
    }

    /**
     * Currency of the feed and the rate from the base currency
     *
     * @param StoreView $context
     * @return array {code: string, rate: float}
     */
    private function currency(StoreView $context): array
    {
        if ($this->currency === null) {
            $baseCode = (string) $context->getStore()->getBaseCurrencyCode();
            $displayCode = $context->getCurrencyCode();

            $rate = 1.0;
            if ($displayCode !== '' && $displayCode !== $baseCode) {
                try {
                    $rate = (float) $context->getStore()->getBaseCurrency()->getRate($displayCode);
                } catch (\Exception $e) {
                    $rate = 0.0;
                }
            }

            // without a rate the amounts stay in the base currency, under its own code
            $this->currency = $rate > 0 && $displayCode !== ''
                ? ['code' => $displayCode, 'rate' => $rate]
                : ['code' => $baseCode, 'rate' => 1.0];
        }

        return $this->currency;
    }
}
