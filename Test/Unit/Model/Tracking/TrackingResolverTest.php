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

use Ovebot\Chat\Api\Data\TrackingResultInterface;
use Ovebot\Chat\Api\Data\TrackingResultInterfaceFactory;
use Ovebot\Chat\Api\TrackingFinderInterface;
use Ovebot\Chat\Model\Config;
use Ovebot\Chat\Model\Tracking\TrackingResolver;
use Ovebot\Chat\Model\Tracking\TrackingResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TrackingResolverTest extends TestCase
{
    private const ORDER_ID = 42;

    /**
     * @var string[] codes of the finders asked, in order
     */
    private $asked = [];

    /**
     * @var string[]
     */
    private $logged = [];

    protected function setUp(): void
    {
        $this->asked = [];
        $this->logged = [];
    }

    /**
     * A finder that gives $result, or throws it when it is an exception
     *
     * @param string $code
     * @param TrackingResultInterface|\Exception|null $result
     * @param bool $available
     * @return TrackingFinderInterface
     */
    private function finder(string $code, $result, bool $available = true): TrackingFinderInterface
    {
        $finder = $this->createMock(TrackingFinderInterface::class);
        $finder->method('isAvailable')->willReturn($available);
        $finder->method('find')->willReturnCallback(function ($orderId) use ($code, $result) {
            $this->assertSame(self::ORDER_ID, $orderId);
            $this->asked[] = $code;
            if ($result instanceof \Exception) {
                throw $result;
            }

            return $result;
        });

        return $finder;
    }

    private function resolver(array $finders, array $keptCodes = []): TrackingResolver
    {
        $config = $this->createMock(Config::class);
        $config->method('getTrackingFinderCodes')->willReturn($keptCodes);

        $factory = $this->getMockBuilder(TrackingResultInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(function (array $data) {
            return new TrackingResult($data['carrier'], $data['awb'], $data['trackingUrl']);
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->logged[] = $message;
        });

        return new TrackingResolver($config, $factory, $logger, $finders);
    }

    private function source(string $code, $result, int $sortOrder): array
    {
        return $this->definition($this->finder($code, $result), $sortOrder);
    }

    private function parts(TrackingResultInterface $result): array
    {
        return [$result->getCarrier(), $result->getAwb(), $result->getTrackingUrl()];
    }

    private function definition(TrackingFinderInterface $finder, int $sortOrder, string $label = ''): array
    {
        return ['finder' => $finder, 'label' => $label, 'sortOrder' => $sortOrder];
    }

    public function testTheFirstNumberWithAPageWins()
    {
        $resolver = $this->resolver([
            'second' => $this->source('second', new TrackingResult('DPD', 'D1', 'https://d/1'), 20),
            'first' => $this->source('first', new TrackingResult('Sameday', 'S1', 'https://s/1'), 10),
        ]);

        $result = $resolver->resolve(self::ORDER_ID);

        $this->assertSame(['Sameday', 'S1', 'https://s/1'], $this->parts($result));
        $this->assertSame(['first'], $this->asked, 'sorted by sortOrder; the rest is not asked');
    }

    public function testAPageIsBorrowedForTheSameNumber()
    {
        $resolver = $this->resolver([
            'native' => $this->source('native', new TrackingResult('FAN Courier', 'F123', null), 10),
            'other' => $this->source('other', new TrackingResult('X', 'D9', 'https://x/D9'), 20),
            'fan' => $this->source('fan', new TrackingResult('FAN', 'f123', 'https://fan/F123'), 30),
        ]);

        $result = $resolver->resolve(self::ORDER_ID);

        $this->assertSame(
            ['FAN Courier', 'F123', 'https://fan/F123'],
            $this->parts($result)
        );
        $this->assertSame(['native', 'other', 'fan'], $this->asked);
    }

    public function testNeverThePageOfAnotherNumber()
    {
        $resolver = $this->resolver([
            'native' => $this->source('native', new TrackingResult('Custom', 'N1', null), 10),
            'other' => $this->source('other', new TrackingResult('DPD', 'D1', 'https://d/1'), 20),
        ]);

        $result = $resolver->resolve(self::ORDER_ID);

        $this->assertSame(['Custom', 'N1', null], $this->parts($result));
    }

    public function testNothingFound()
    {
        $resolver = $this->resolver([
            'native' => $this->source('native', null, 10),
            'blank' => $this->source('blank', new TrackingResult('DPD', '  ', 'https://d/'), 20),
        ]);

        $this->assertNull($resolver->resolve(self::ORDER_ID));
        $this->assertSame(['native', 'blank'], $this->asked);
    }

    public function testABrokenSourceIsLoggedAndSkipped()
    {
        $resolver = $this->resolver([
            'broken' => $this->source('broken', new \RuntimeException('table missing'), 10),
            'native' => $this->source('native', new TrackingResult('UPS', 'U1', 'https://u/1'), 20),
        ]);

        $this->assertSame('U1', $resolver->resolve(self::ORDER_ID)->getAwb());
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('"broken"', $this->logged[0]);
        $this->assertStringContainsString('table missing', $this->logged[0]);
    }

    public function testAnUnavailableSourceIsNotAsked()
    {
        $resolver = $this->resolver([
            'off' => $this->definition($this->finder('off', new TrackingResult('X', 'X1', 'https://x'), false), 10),
            'native' => $this->source('native', null, 20),
        ]);

        $this->assertNull($resolver->resolve(self::ORDER_ID));
        $this->assertSame(['native'], $this->asked);
    }

    public function testOnlyTheSourcesKeptByTheMerchant()
    {
        $resolver = $this->resolver(
            [
                'native' => $this->source('native', new TrackingResult('A', 'A1', 'https://a'), 10),
                'sameday' => $this->source('sameday', new TrackingResult('S', 'S1', 'https://s'), 20),
            ],
            ['Sameday']
        );

        $this->assertSame('S1', $resolver->resolve(self::ORDER_ID)->getAwb());
        $this->assertSame(['sameday'], $this->asked);
    }

    public function testLabelsOfTheValidSourcesInOrder()
    {
        $finder = $this->finder('any', null);
        $resolver = $this->resolver([
            'sameday' => $this->definition($finder, 20, 'Sameday'),
            'Native' => $this->definition($finder, 10, 'Magento shipment tracking'),
            'dpd' => $this->definition($finder, 20),
            'fan courier' => $this->definition($finder, 30, 'Code with a space'),
            'a,b' => $this->definition($finder, 30, 'Code with a comma'),
            'no_finder' => ['finder' => new \stdClass(), 'label' => 'Not a finder', 'sortOrder' => 1],
            'no_array' => $finder,
        ]);

        // the code is the label when there is none; sortOrder ties are broken by code
        $this->assertSame(
            ['native' => 'Magento shipment tracking', 'dpd' => 'dpd', 'sameday' => 'Sameday'],
            $resolver->getLabels()
        );
    }

    public function testTranslatedLabel()
    {
        $resolver = $this->resolver([
            'native' => ['finder' => $this->finder('native', null), 'label' => __('Magento shipment tracking')],
        ]);

        $this->assertSame(['native' => 'Magento shipment tracking'], $resolver->getLabels());
    }
}
