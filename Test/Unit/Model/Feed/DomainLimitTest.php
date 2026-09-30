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

use Ovebot\Chat\Model\Feed\DomainLimit;
use PHPUnit\Framework\TestCase;

class DomainLimitTest extends TestCase
{
    /**
     * @return array
     */
    public static function domains(): array
    {
        return [
            'test shop' => ['demo.shops.ro', 100],
            'deeper subdomain' => ['a.demo.shops.ro', 100],
            'upper case' => ['Demo.SHOPS.ro', 100],
            'the domain itself' => ['shops.ro', 0],
            'domain ending in the same text' => ['myshops.ro', 0],
            'subdomain of such a domain' => ['www.myshops.ro', 0],
            'domain in the middle' => ['shops.ro.example.com', 0],
            'another shop' => ['www.shop-test.ro', 0],
            'no domain' => ['', 0],
        ];
    }

    /**
     * @dataProvider domains
     * @param string $domain
     * @param int $expected
     */
    public function testLimitByDomain(string $domain, int $expected)
    {
        $this->assertSame($expected, (new DomainLimit(['.shops.ro'], 100))->forDomain($domain));
    }

    public function testNoDomainsNoLimit()
    {
        $this->assertSame(0, (new DomainLimit())->forDomain('demo.shops.ro'));
        $this->assertSame(0, (new DomainLimit(['', '  ', '.']))->forDomain('demo.shops.ro'));
    }

    public function testConfiguredEndingsAreTrimmedAndLowerCaseAndTheDotIsOptional()
    {
        $limit = new DomainLimit([' .Shops.RO ', 'shop-test.ro'], 25);

        $this->assertSame(25, $limit->forDomain('demo.shops.ro'));
        $this->assertSame(25, $limit->forDomain('www.shop-test.ro'));
        $this->assertSame(0, $limit->forDomain('shop-test.ro'));
    }

    public function testANegativeLimitIsNoLimit()
    {
        $this->assertSame(0, (new DomainLimit(['.shops.ro'], -5))->forDomain('demo.shops.ro'));
    }
}
