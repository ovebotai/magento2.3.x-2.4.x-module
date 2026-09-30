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

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Ovebot\Chat\Model\Feed\CategoryPathProvider;
use Ovebot\Chat\Model\Util\Text;
use PHPUnit\Framework\TestCase;

class CategoryPathProviderTest extends TestCase
{
    /**
     * Tree below the root category: Femei (3) > Bluze (4) > Tricouri (5), Bărbați (6) > Pantaloni (7),
     * Reduceri (8, disabled) > Lichidare (9)
     */
    private const CATEGORIES = [
        3 => ['name' => 'Femei', 'active' => true, 'chain' => [3]],
        4 => ['name' => 'Bluze', 'active' => true, 'chain' => [3, 4]],
        5 => ['name' => 'Tricouri', 'active' => true, 'chain' => [3, 4, 5]],
        6 => ['name' => 'Bărbați', 'active' => true, 'chain' => [6]],
        7 => ['name' => 'Pantaloni', 'active' => true, 'chain' => [6, 7]],
        8 => ['name' => 'Reduceri', 'active' => false, 'chain' => [8]],
        9 => ['name' => 'Lichidare', 'active' => true, 'chain' => [8, 9]],
        10 => ['name' => '', 'active' => true, 'chain' => [6, 10]],
    ];

    private function provider(): CategoryPathProvider
    {
        $factory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        return new CategoryPathProvider($factory, new Text());
    }

    /**
     * @dataProvider products
     */
    public function testResolve(array $categoryIds, ?string $expected)
    {
        $this->assertSame($expected, $this->provider()->resolve($categoryIds, self::CATEGORIES));
    }

    public static function products(): array
    {
        return [
            'deepest category wins' => [[3, 5, 6], 'Femei > Bluze > Tricouri'],
            'ids as strings, any order' => [['7', '3'], 'Bărbați > Pantaloni'],
            'same depth: the lower id' => [[7, 4], 'Femei > Bluze'],
            'category under a disabled one cannot be reached' => [[9, 6], 'Bărbați'],
            'disabled category' => [[8], null],
            'category without a name' => [[10, 6], 'Bărbați'],
            'category of another tree' => [[55], null],
            'no category' => [[], null],
        ];
    }
}
