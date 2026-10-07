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

use Magento\Catalog\Model\Product;
use Ovebot\Chat\Model\Config;
use Ovebot\Chat\Model\Feed\AttributeProvider;
use Ovebot\Chat\Model\Feed\GtinProvider;
use PHPUnit\Framework\TestCase;

class GtinProviderTest extends TestCase
{
    /**
     * @var int how many times the attributes were looked up
     */
    private $lookups = 0;

    /**
     * @param string $setting value of the GTIN option
     * @param string[] $existing codes of the attributes of the catalog
     * @param array $values code => value of the product
     * @return GtinProvider
     */
    private function provider(string $setting, array $existing, array $values = []): GtinProvider
    {
        $this->lookups = 0;

        $config = $this->createMock(Config::class);
        $config->method('getGtinAttribute')->willReturn($setting);

        $attributes = $this->createMock(AttributeProvider::class);
        $attributes->method('exists')->willReturnCallback(function (string $code) use ($existing) {
            $this->lookups++;

            return in_array($code, $existing, true);
        });
        $attributes->method('read')->willReturnCallback(function (string $code) use ($values) {
            return isset($values[$code]) ? $values[$code] : '';
        });

        return new GtinProvider($config, $attributes);
    }

    public function testAutoTakesTheFirstKnownCodeThatExists()
    {
        $provider = $this->provider(Config::GTIN_AUTO, ['upc', 'ean', 'color'], ['ean' => ' 5901234123457 ']);

        $this->assertSame('ean', $provider->getCode());
        $this->assertSame('5901234123457', $provider->get($this->createMock(Product::class), 1));
    }

    public function testAutoWithoutAKnownAttributeGivesNoColumn()
    {
        $provider = $this->provider(Config::GTIN_AUTO, ['color', 'cod_ean']);

        $this->assertSame('', $provider->getCode());
        $this->assertSame('', $provider->get($this->createMock(Product::class), 1));
    }

    public function testAChosenAttributeIsUsedWhenItExists()
    {
        $provider = $this->provider('cod_ean', ['cod_ean', 'ean'], ['cod_ean' => '4006381333931', 'ean' => '1']);

        $this->assertSame('cod_ean', $provider->getCode());
        $this->assertSame('4006381333931', $provider->get($this->createMock(Product::class), 1));
    }

    public function testAChosenAttributeThatWasDeletedGivesNoColumn()
    {
        $this->assertSame('', $this->provider('cod_ean', ['ean'])->getCode());
    }

    public function testNoneGivesNoColumn()
    {
        $provider = $this->provider('', ['gtin', 'ean'], ['gtin' => '123']);

        $this->assertSame('', $provider->getCode());
        $this->assertSame('', $provider->get($this->createMock(Product::class), 1));
    }

    public function testTheCodeIsSettledOnce()
    {
        $provider = $this->provider(Config::GTIN_AUTO, ['barcode']);
        $provider->getCode();
        $provider->getCode();
        $provider->get($this->createMock(Product::class), 1);

        $this->assertSame(count(GtinProvider::CANDIDATES) - 1, $this->lookups);
    }
}
