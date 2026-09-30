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

/**
 * A shorter feed on the test shops of the authors: when the domain of the shop ends in one of the configured
 * endings, a feed asked for without "limit" gives only its first items, always the same ones (the order of the
 * feed: products by id, variants by parent and id). "limit=0" still gives the whole feed.
 *
 * An ending is matched after a dot: with ".example.com" configured, "shop.example.com" is limited, while
 * "example.com" itself and "myexample.com" are not. The endings and the number of items are arguments in
 * etc/di.xml; with no ending, nothing changes.
 */
class DomainLimit
{
    /**
     * @var string[] configured endings, each with a leading dot: ".example.com"
     */
    private $suffixes;

    /**
     * @var int
     */
    private $limit;

    /**
     * @param string[] $domains endings of the limited domains, for example ".example.com" (the dot may be left out)
     * @param int $limit most items of the feed on those domains
     */
    public function __construct(array $domains = [], int $limit = 100)
    {
        $this->suffixes = [];
        foreach ($domains as $domain) {
            $domain = ltrim(strtolower(trim((string) $domain)), '.');
            if ($domain !== '') {
                $this->suffixes[] = '.' . $domain;
            }
        }
        $this->limit = max(0, $limit);
    }

    /**
     * Most items of the feed for a domain
     *
     * @param string $domain host of the shop
     * @return int 0 for no limit
     */
    public function forDomain(string $domain): int
    {
        $domain = strtolower($domain);
        if ($domain === '') {
            return 0;
        }

        foreach ($this->suffixes as $suffix) {
            if (strlen($domain) > strlen($suffix) && substr($domain, -strlen($suffix)) === $suffix) {
                return $this->limit;
            }
        }

        return 0;
    }
}
