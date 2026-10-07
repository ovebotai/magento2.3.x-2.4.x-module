<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Storefront;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Option;
use Ovebot\Chat\Model\Storefront\CartProvider;
use PHPUnit\Framework\TestCase;

class CartProviderTest extends TestCase
{
    /**
     * @param array $lines [product id, type, child id|null, sku, name, price with tax, price, qty]
     * @param string $currency
     * @return CartProvider
     */
    private function provider(array $lines, string $currency = 'RON'): CartProvider
    {
        $items = [];
        foreach ($lines as $line) {
            $option = null;
            if ($line[2] !== null) {
                $option = $this->createMock(Option::class);
                $option->method('getData')->with('product_id')->willReturn($line[2]);
            }

            $data = ['product_id' => $line[0], 'name' => $line[4], 'price_incl_tax' => $line[5]];
            $item = $this->createMock(Item::class);
            $item->method('getData')->willReturnCallback(function ($key = '') use ($data) {
                return isset($data[$key]) ? $data[$key] : null;
            });
            $item->method('getProductType')->willReturn($line[1]);
            $item->method('getOptionByCode')->willReturnCallback(function ($code) use ($option) {
                return $code === 'simple_product' ? $option : null;
            });
            $item->method('getSku')->willReturn($line[3]);
            $item->method('getPrice')->willReturn($line[6]);
            $item->method('getQty')->willReturn($line[7]);
            $items[] = $item;
        }

        $quote = $this->createMock(Quote::class);
        $quote->method('getData')->with('quote_currency_code')->willReturn($currency);
        $quote->method('getAllVisibleItems')->willReturn($items);

        $session = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $session->method('getQuote')->willReturn($quote);

        return new CartProvider($session);
    }

    public function testEmptyCart()
    {
        $this->assertSame(['count' => 0, 'items' => []], $this->provider([])->get());
    }

    public function testLinesAreNamedAsTheFeedNamesTheProducts()
    {
        $cart = $this->provider([
            ['1042', 'simple', null, 'WHP-001-BLK', 'Wireless Headphones Pro', '249.99', '210.08', '2.0000'],
            // a configurable product: the parent line with the chosen simple product in its option
            ['14', 'configurable', '31', 'TRICOU-BBC-ROSU-M', 'Tricou bumbac', '99.9', '83.95', '1'],
            // a configurable line whose option is missing is named by the parent alone
            ['15', 'configurable', null, 'X', 'Alt tricou', '10', '8', '1'],
        ], 'EUR')->get();

        $this->assertSame(
            [
                'count' => 4,
                'items' => [
                    [
                        'ref' => '1042',
                        'sku' => 'WHP-001-BLK',
                        'name' => 'Wireless Headphones Pro',
                        'price' => 249.99,
                        'currency' => 'EUR',
                        'quantity' => 2,
                    ],
                    [
                        'ref' => '14-31',
                        'sku' => 'TRICOU-BBC-ROSU-M',
                        'name' => 'Tricou bumbac',
                        'price' => 99.9,
                        'currency' => 'EUR',
                        'quantity' => 1,
                    ],
                    [
                        'ref' => '15',
                        'sku' => 'X',
                        'name' => 'Alt tricou',
                        'price' => 10.0,
                        'currency' => 'EUR',
                        'quantity' => 1,
                    ],
                ],
            ],
            $cart
        );
    }

    public function testPriceWithoutTaxWhenTheTaxedOneIsNotThereAndLinesWithoutQuantityAreSkipped()
    {
        $cart = $this->provider([
            ['7', 'simple', null, '', 'No tax yet', null, '12.345', '3'],
            ['8', 'simple', null, 'Z', 'Nothing', '5', '5', '0'],
        ])->get();

        $this->assertSame(3, $cart['count']);
        $this->assertCount(1, $cart['items']);
        $this->assertSame(12.35, $cart['items'][0]['price']);
        $this->assertArrayNotHasKey('sku', $cart['items'][0]);
        $this->assertSame(['ref', 'name', 'price', 'currency', 'quantity'], array_keys($cart['items'][0]));
    }
}
