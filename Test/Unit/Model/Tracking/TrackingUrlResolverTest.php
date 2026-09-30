<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Tracking;

use Ovebot\Chat\Model\Config;
use Ovebot\Chat\Model\Tracking\TrackingUrlResolver;
use PHPUnit\Framework\TestCase;

class TrackingUrlResolverTest extends TestCase
{
    private const BUILT_IN = [
        'sameday' => 'https://sameday.ro/#awb={code}',
        'fancourier' => 'https://www.fancourier.ro/awb-tracking/?tracking={code}',
        'fan' => 'https://www.fancourier.ro/awb-tracking/?tracking={code}',
        'dpd' => 'https://tracking.dpd.ro/?shipmentNumber={code}&language=ro',
        'ups' => 'https://www.ups.com/track?tracknum={code}',
    ];

    private function resolver(array $merchant = [], array $builtIn = self::BUILT_IN): TrackingUrlResolver
    {
        $config = $this->createMock(Config::class);
        $config->method('getTrackingUrlTemplates')->willReturn($merchant);

        return new TrackingUrlResolver($config, $builtIn);
    }

    public function testByCarrierCode()
    {
        $this->assertSame(
            'https://www.ups.com/track?tracknum=1Z999',
            $this->resolver()->resolve('ups', 'United Parcel Service', '1Z999')
        );
    }

    /**
     * @dataProvider titles
     */
    public function testByTitleOfACustomCarrier(string $title, ?string $expected)
    {
        $this->assertSame($expected, $this->resolver()->resolve('custom', $title, 'AWB1'));
    }

    public static function titles(): array
    {
        $fan = 'https://www.fancourier.ro/awb-tracking/?tracking=AWB1';
        $dpd = 'https://tracking.dpd.ro/?shipmentNumber=AWB1&language=ro';

        return [
            'name as written by the courier' => ['FAN Courier', $fan],
            'with a dash' => ['Fan-Courier', $fan],
            'one word' => ['FANCOURIER', $fan],
            'short name' => ['FAN', $fan],
            'words written apart' => ['Same Day', 'https://sameday.ro/#awb=AWB1'],
            'name inside a longer title' => ['Livrare DPD Romania', $dpd],
            'diacritics' => ['Sămeday Curier', 'https://sameday.ro/#awb=AWB1'],
            'name only as part of a word' => ['Groups Delivery', null],
            'another word starting the same' => ['Fantastic Delivery', null],
            'unknown carrier' => ['Posta Romana', null],
            'no title' => ['', null],
        ];
    }

    public function testTheCarrierCodeComesBeforeTheTitle()
    {
        $this->assertSame(
            'https://tracking.dpd.ro/?shipmentNumber=AWB1&language=ro',
            $this->resolver()->resolve('dpd', 'Sameday', 'AWB1')
        );
    }

    public function testShippingMethodOfTheOrderWhenTheTitleSaysNothing()
    {
        $this->assertSame(
            'https://www.fancourier.ro/awb-tracking/?tracking=F1',
            $this->resolver()->resolve('custom', 'AWB', 'F1', 'fancourier_standard')
        );
        $this->assertNull($this->resolver()->resolve('custom', 'AWB', 'F1', 'flatrate_flatrate'));
        $this->assertNull($this->resolver()->resolve('custom', 'AWB', 'F1', ''));
    }

    public function testTheTitleComesBeforeTheShippingMethod()
    {
        // chosen at checkout: FAN Courier; carried by: Sameday
        $this->assertSame(
            'https://sameday.ro/#awb=S1',
            $this->resolver()->resolve('custom', 'Sameday', 'S1', 'fancourier_standard')
        );
    }

    public function testMerchantTemplateForTheCodeOfACarrierModule()
    {
        $resolver = $this->resolver(['samedaycourier' => 'https://example.com/sameday/{code}']);

        $this->assertSame(
            'https://example.com/sameday/S1',
            $resolver->resolve('custom', 'AWB', 'S1', 'samedaycourier_locker')
        );
    }

    public function testMerchantTemplatesComeFirst()
    {
        $resolver = $this->resolver(['sameday' => 'https://example.com/track/{code}']);

        $this->assertSame('https://example.com/track/AWB1', $resolver->resolve('custom', 'Sameday', 'AWB1'));
        $this->assertSame(
            'https://www.fancourier.ro/awb-tracking/?tracking=AWB1',
            $resolver->resolve('custom', 'FAN Courier', 'AWB1'),
            'the built-in templates still apply to the other carriers'
        );
    }

    public function testMerchantTemplateNameWithDiacriticsAndSpaces()
    {
        $resolver = $this->resolver(['poșta_română' => 'https://www.posta-romana.ro/track?awb={code}']);

        $this->assertSame(
            'https://www.posta-romana.ro/track?awb=RR1',
            $resolver->resolve('custom', 'Poșta Română', 'RR1')
        );
    }

    public function testTheNumberIsEncodedInTheUrl()
    {
        $this->assertSame(
            'https://sameday.ro/#awb=A%2FB%20C%26D',
            $this->resolver()->resolve('sameday', '', ' A/B C&D ')
        );
    }

    public function testTemplatesThatAreNotWebAddressesAreIgnored()
    {
        $resolver = $this->resolver(
            ['sameday' => 'javascript:alert({code})', 'dpd' => 'ftp://dpd/{code}'],
            ['sameday' => 'https://sameday.ro/#awb={code}']
        );

        $this->assertSame('https://sameday.ro/#awb=AWB1', $resolver->resolve('sameday', '', 'AWB1'));
        $this->assertNull($resolver->resolve('dpd', '', 'AWB1'));
    }

    public function testNoNumberNoPage()
    {
        $this->assertNull($this->resolver()->resolve('ups', '', '  '));
    }
}
