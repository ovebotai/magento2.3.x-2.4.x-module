<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Feed;

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Directory\Model\Currency;
use Magento\Store\Model\Store;
use Ovebot\Chat\Model\Feed\PriceResolver;
use Ovebot\Chat\Model\StoreContext\StoreView;
use PHPUnit\Framework\TestCase;

class PriceResolverTest extends TestCase
{
    /**
     * @var float tax added by the helper, in percent
     */
    private $tax = 19.0;

    /**
     * @var float|bool|\Exception rate from the base currency to the display currency
     */
    private $rate = 1.0;

    /**
     * @var array amounts the tax was asked for
     */
    private $taxAsked = [];

    protected function setUp(): void
    {
        $this->tax = 19.0;
        $this->rate = 1.0;
        $this->taxAsked = [];
    }

    private function resolver(): PriceResolver
    {
        $helper = $this->createMock(CatalogHelper::class);
        $helper->method('getTaxPrice')->willReturnCallback(
            function ($product, $price, $includingTax, $shipping, $billing, $ctc, $store, $includes, $round) {
                $this->assertTrue($includingTax);
                $this->assertInstanceOf(Store::class, $store);
                $this->assertFalse($round, 'rounded once, after the currency conversion');
                $this->taxAsked[] = [$product->getData('tax_class_id'), $price];

                return $price * (1 + $this->tax / 100);
            }
        );

        return new PriceResolver($helper);
    }

    private function context(string $base = 'RON', string $display = 'RON'): StoreView
    {
        $currency = $this->createMock(Currency::class);
        $currency->method('getRate')->willReturnCallback(function ($code) use ($display) {
            $this->assertSame($display, $code);
            if ($this->rate instanceof \Exception) {
                throw $this->rate;
            }

            return $this->rate;
        });

        $store = $this->createMock(Store::class);
        $store->method('getBaseCurrencyCode')->willReturn($base);
        $store->method('getBaseCurrency')->willReturn($currency);

        $context = $this->createMock(StoreView::class);
        $context->method('getStore')->willReturn($store);
        $context->method('getCurrencyCode')->willReturn($display);

        return $context;
    }

    private function product(string $type, $price, $final, $min, $taxClass = '2'): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn($type);
        $product->method('getData')->willReturnCallback(function ($key) use ($price, $final, $min, $taxClass) {
            $index = ['price' => $price, 'final_price' => $final, 'min_price' => $min, 'tax_class_id' => $taxClass];

            return isset($index[$key]) ? $index[$key] : null;
        });

        return $product;
    }

    /**
     * @dataProvider amounts
     */
    public function testPick(string $type, float $price, float $final, float $min, float $regular, float $reduced)
    {
        $this->assertSame(
            ['price' => $regular, 'final' => $reduced],
            $this->resolver()->pick($type, $price, $final, $min)
        );
    }

    public static function amounts(): array
    {
        return [
            'simple, no reduction' => ['simple', 100.0, 100.0, 100.0, 100.0, 100.0],
            'simple, special price' => ['simple', 100.0, 80.0, 80.0, 100.0, 80.0],
            'dynamic bundle has only the lowest price' => ['bundle', 0.0, 0.0, 45.5, 45.5, 45.5],
            'fixed bundle is quoted by its lowest price' => ['bundle', 60.0, 60.0, 72.0, 72.0, 72.0],
            'grouped' => ['grouped', 0.0, 0.0, 12.0, 12.0, 12.0],
            'unknown type without own price' => ['subscription', 0.0, 0.0, 30.0, 30.0, 30.0],
            'final above the regular price' => ['simple', 50.0, 60.0, 60.0, 60.0, 60.0],
        ];
    }

    public function testPricesGetTaxAndTwoDecimals()
    {
        $product = $this->product('simple', '100.0000', '79.9900', '79.9900');

        $prices = $this->resolver()->resolve($product, $this->context());

        $this->assertSame(['price' => 119.0, 'special' => 95.19], $prices);
    }

    public function testTaxIsAskedOnceForEachTaxClass()
    {
        $resolver = $this->resolver();
        $context = $this->context();

        $resolver->resolve($this->product('simple', '100', '80', '80'), $context);
        $resolver->resolve($this->product('simple', '10', '10', '10'), $context);
        $resolver->resolve($this->product('simple', '20', '20', '20', '5'), $context);

        $this->assertSame([['2', 100.0], ['5', 100.0]], $this->taxAsked);
    }

    public function testEachTaxClassKeepsItsOwnTax()
    {
        $resolver = $this->resolver();
        $context = $this->context();

        $standard = $resolver->resolve($this->product('simple', '100', '100', '100'), $context);
        $this->tax = 9.0;
        $reduced = $resolver->resolve($this->product('simple', '100', '100', '100', '5'), $context);
        $again = $resolver->resolve($this->product('simple', '200', '200', '200'), $context);

        $this->assertSame(119.0, $standard['price']);
        $this->assertSame(109.0, $reduced['price']);
        $this->assertSame(238.0, $again['price']);
    }

    public function testNoSpecialWithoutAReduction()
    {
        $prices = $this->resolver()->resolve($this->product('simple', '50', '50', '50'), $this->context());

        $this->assertSame(['price' => 59.5, 'special' => null], $prices);
    }

    public function testPricesAreConvertedToTheDisplayCurrency()
    {
        $this->tax = 0.0;
        $this->rate = 0.2;
        $resolver = $this->resolver();
        $context = $this->context('RON', 'EUR');

        $this->assertSame('EUR', $resolver->getCurrencyCode($context));
        $this->assertSame(
            ['price' => 20.0, 'special' => 16.0],
            $resolver->resolve($this->product('simple', '100', '80', '80'), $context)
        );
    }

    /**
     * @dataProvider missingRates
     */
    public function testWithoutARateThePricesStayInTheBaseCurrency($rate)
    {
        $this->tax = 0.0;
        $this->rate = $rate;
        $resolver = $this->resolver();
        $context = $this->context('RON', 'EUR');

        $this->assertSame('RON', $resolver->getCurrencyCode($context));
        $this->assertSame(
            ['price' => 100.0, 'special' => null],
            $resolver->resolve($this->product('simple', '100', '100', '100'), $context)
        );
    }

    public static function missingRates(): array
    {
        return [
            'no rate saved' => [false],
            'zero' => [0.0],
            'lookup fails' => [new \RuntimeException('no rates')],
        ];
    }

    public function testPriceThatRoundsToZeroGivesNoItem()
    {
        $this->tax = 0.0;
        $this->rate = 0.2;

        $this->assertNull(
            $this->resolver()->resolve($this->product('simple', '0.02', '0.02', '0.02'), $this->context('RON', 'EUR'))
        );
    }
}
