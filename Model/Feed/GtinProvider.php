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

use Magento\Catalog\Model\Product;
use Ovebot\Chat\Model\Config;

/**
 * The GTIN (EAN/UPC) of the feed items.
 *
 * Magento has no attribute for it, so the code of the attribute comes from Stores > Configuration: a chosen
 * attribute, none, or "auto", which takes the first existing attribute among the codes merchants usually give
 * to a barcode. The code is settled once per feed.
 */
class GtinProvider
{
    /**
     * Codes looked for by "auto", in this order
     */
    public const CANDIDATES = ['gtin', 'ean', 'ean13', 'upc', 'barcode', 'isbn'];

    /**
     * @var Config
     */
    private $config;

    /**
     * @var AttributeProvider
     */
    private $attributes;

    /**
     * @var string|null code of the attribute, once settled; empty for no GTIN column
     */
    private $code;

    /**
     * @param Config $config
     * @param AttributeProvider $attributes
     */
    public function __construct(Config $config, AttributeProvider $attributes)
    {
        $this->config = $config;
        $this->attributes = $attributes;
    }

    /**
     * Code of the attribute the GTIN is read from; empty when the feed has no GTIN column
     *
     * @return string
     */
    public function getCode(): string
    {
        if ($this->code !== null) {
            return $this->code;
        }

        $setting = $this->config->getGtinAttribute();
        $candidates = $setting === Config::GTIN_AUTO ? self::CANDIDATES : [$setting];

        $this->code = '';
        foreach ($candidates as $candidate) {
            if ($this->attributes->exists($candidate)) {
                $this->code = $candidate;
                break;
            }
        }

        return $this->code;
    }

    /**
     * GTIN of a product; empty when it has none
     *
     * @param Product $product
     * @param int $storeId
     * @return string
     */
    public function get(Product $product, int $storeId): string
    {
        $code = $this->getCode();

        return $code !== '' ? trim($this->attributes->read($code, $product, $storeId)) : '';
    }
}
