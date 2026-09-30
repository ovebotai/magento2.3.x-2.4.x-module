<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Tracking;

use Ovebot\Chat\Model\Config;

/**
 * Builds the public tracking page of a shipment from a URL template with {code}.
 *
 * The template is looked up by the carrier code of the tracking number, then by its title, then by the carrier
 * of the order's shipping method (the "fancourier" of "fancourier_standard", like shipping_code in OpenCart). The
 * title comes before the shipping method: a tracking number added by hand as "Custom Value" with the title
 * "Sameday" names the courier that carries the parcel, even when the customer chose another at checkout.
 * The templates set in Stores > Configuration come before the built-in ones (di.xml, argument "templates").
 *
 * A title matches a template name when the name equals one of its words, or several of its words written
 * together, ignoring case, diacritics and punctuation: "FAN Courier" matches "fan" and "fancourier", "Same Day"
 * matches "sameday", while "Groups Delivery" does not match "ups".
 */
class TrackingUrlResolver
{
    private const PLACEHOLDER = '{code}';

    /**
     * Words of a title looked at; longer titles are cut
     */
    private const MAX_WORDS = 10;

    // \z, not $: in PHP "$" also matches before a trailing line break
    private const URL_PATTERN = '#^https?://[^\s]+\z#i';

    private const DIACRITICS = [
        'ă' => 'a', 'â' => 'a', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ď' => 'd',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ę' => 'e', 'ě' => 'e',
        'î' => 'i', 'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ő' => 'o', 'ř' => 'r',
        'ș' => 's', 'ş' => 's', 'ś' => 's', 'š' => 's', 'ß' => 'ss', 'ț' => 't', 'ţ' => 't', 'ť' => 't',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ű' => 'u', 'ů' => 'u', 'ý' => 'y',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
    ];

    /**
     * @var Config
     */
    private $config;

    /**
     * @var array
     */
    private $builtIn;

    /**
     * @var string[]|null name => template, the merchant's first
     */
    private $templates;

    /**
     * @param Config $config
     * @param array $templates built-in templates: carrier name => URL with {code}
     */
    public function __construct(Config $config, array $templates = [])
    {
        $this->config = $config;
        $this->builtIn = $templates;
    }

    /**
     * Public tracking page of a shipment; null when no template fits
     *
     * @param string $carrierCode carrier code of the tracking number ("ups", "custom", ..)
     * @param string $title carrier title of the tracking number ("FAN Courier")
     * @param string $awb tracking number
     * @param string $shippingMethod shipping method of the order ("fancourier_standard"); empty when unknown
     * @return string|null
     */
    public function resolve(string $carrierCode, string $title, string $awb, string $shippingMethod = ''): ?string
    {
        $awb = trim($awb);
        if ($awb === '') {
            return null;
        }

        $templates = $this->templates();
        $template = $this->byCode($carrierCode, $templates);
        if ($template === null) {
            $template = $this->byTitle($title, $templates);
        }
        if ($template === null) {
            // Magento writes the shipping method as "{carrier code}_{method code}"
            $parts = explode('_', trim($shippingMethod), 2);
            $template = $this->byCode($parts[0], $templates);
        }

        return $template !== null ? str_replace(self::PLACEHOLDER, rawurlencode($awb), $template) : null;
    }

    /**
     * The template named like the carrier code
     *
     * @param string $code
     * @param string[] $templates
     * @return string|null
     */
    private function byCode(string $code, array $templates): ?string
    {
        $code = $this->compact($code);

        return $code !== '' && isset($templates[$code]) ? $templates[$code] : null;
    }

    /**
     * The first template whose name is among the words of the title
     *
     * @param string $title
     * @param string[] $templates
     * @return string|null
     */
    private function byTitle(string $title, array $templates): ?string
    {
        $words = array_slice($this->words($title), 0, self::MAX_WORDS);
        $candidates = [];
        $count = count($words);
        for ($start = 0; $start < $count; $start++) {
            $joined = '';
            for ($end = $start; $end < $count; $end++) {
                $joined .= $words[$end];
                $candidates[$joined] = true;
            }
        }

        foreach ($templates as $name => $template) {
            if (isset($candidates[$name])) {
                return $template;
            }
        }

        return null;
    }

    /**
     * The merchant's templates, then the built-in ones; names compacted, invalid URLs left out
     *
     * @return string[]
     */
    private function templates(): array
    {
        if ($this->templates === null) {
            $this->templates = [];
            foreach ([$this->config->getTrackingUrlTemplates(), $this->builtIn] as $list) {
                foreach ($list as $name => $url) {
                    $name = $this->compact((string) $name);
                    $url = is_scalar($url) ? trim((string) $url) : '';
                    if ($name !== '' && !isset($this->templates[$name]) && preg_match(self::URL_PATTERN, $url)) {
                        $this->templates[$name] = $url;
                    }
                }
            }
        }

        return $this->templates;
    }

    /**
     * Words of a text: lower case, without diacritics, split on anything that is not a letter or a digit
     *
     * @param string $text
     * @return string[]
     */
    private function words(string $text): array
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::DIACRITICS);
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? $words : [];
    }

    /**
     * The words of a text written together: "FAN-Courier" gives "fancourier"
     *
     * @param string $text
     * @return string
     */
    private function compact(string $text): string
    {
        return implode('', $this->words($text));
    }
}
