<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Config\Source;

use Ovebot\Chat\Model\Config\Source\TrackingFinders;
use Ovebot\Chat\Model\Tracking\TrackingResolver;
use PHPUnit\Framework\TestCase;

class TrackingFindersTest extends TestCase
{
    public function testOptionsAreTheSourcesOfTheOrderLookup()
    {
        $resolver = $this->createMock(TrackingResolver::class);
        $resolver->method('getLabels')->willReturn([
            'native' => 'Magento shipment tracking',
            'sameday' => 'Sameday',
        ]);

        $this->assertSame(
            [
                ['value' => 'native', 'label' => 'Magento shipment tracking'],
                ['value' => 'sameday', 'label' => 'Sameday'],
            ],
            (new TrackingFinders($resolver))->toOptionArray()
        );
    }

    public function testNoSources()
    {
        $resolver = $this->createMock(TrackingResolver::class);
        $resolver->method('getLabels')->willReturn([]);

        $this->assertSame([], (new TrackingFinders($resolver))->toOptionArray());
    }
}
