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

use Ovebot\Chat\Model\Util\Text;

/**
 * Gives a feed item the form Ovebot.ai reads. Works on plain values only, so it knows nothing about Magento.
 */
class ItemMapper
{
    /**
     * @var Text
     */
    private $text;

    /**
     * @param Text $text
     */
    public function __construct(Text $text)
    {
        $this->text = $text;
    }

    /**
     * Build an item
     *
     * For a variant, "options" holds what it is chosen by: the values are added to the name and to the
     * attributes, and the URL gets the fragment the product page reads to select the variant.
     *
     * The reference is the id of the product, "{id}", for a variant "{parent id}-{child id}": what the
     * add-to-cart of the storefront takes. The SKU, the GTIN and the other images are columns of their own, present
     * only when the product has them.
     *
     * @param array $data id, sku, gtin, name, description, short_description, category, manufacturer,
     *                    availability, quantity, price, special, currency, image, additional_image_link [url],
     *                    url, attributes {label: value}, options [{attribute_id, label, value_id, value}]
     * @return array
     */
    public function map(array $data): array
    {
        $name = $this->text->line($this->string($data, 'name'));
        $attributes = isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : [];
        $url = $this->string($data, 'url');

        $values = [];
        $selection = [];
        foreach (isset($data['options']) && is_array($data['options']) ? $data['options'] : [] as $option) {
            $attributes[(string) $option['label']] = (string) $option['value'];
            $values[] = (string) $option['value'];
            $selection[] = (int) $option['attribute_id'] . '=' . (int) $option['value_id'];
        }
        if ($values) {
            $name .= ' - ' . implode(', ', $values);
        }
        if ($selection) {
            $url = (string) strtok($url, '#') . '#' . implode('&', $selection);
        }

        $description = $this->plain($this->string($data, 'description'));
        if ($description === '') {
            $description = $this->plain($this->string($data, 'short_description'));
        }

        $category = $this->string($data, 'category');
        $manufacturer = $this->text->line($this->string($data, 'manufacturer'));
        $image = $this->string($data, 'image');

        $price = isset($data['price']) ? (float) $data['price'] : 0.0;
        $special = isset($data['special']) ? (float) $data['special'] : 0.0;

        $item = [
            'ref' => $this->string($data, 'id'),
            'name' => $name,
            'description' => $description,
            'category' => $category !== '' ? $category : null,
            'manufacturer' => $manufacturer !== '' ? $manufacturer : null,
            'availability' => $this->string($data, 'availability'),
            'quantity' => isset($data['quantity']) ? (int) $data['quantity'] : null,
            'price' => $price,
            'special' => $special > 0 && $special < $price ? $special : null,
            'currency' => $this->string($data, 'currency'),
            'image' => $image !== '' ? $image : null,
            'url' => $url,
            // an object also when empty: the feed always has {} here, never []
            'attributes' => (object) $attributes,
        ];

        $sku = $this->string($data, 'sku');
        if ($sku !== '') {
            $item['sku'] = $sku;
        }
        $gtin = trim($this->string($data, 'gtin'));
        if ($gtin !== '') {
            $item['gtin'] = $gtin;
        }
        $images = isset($data['additional_image_link']) && is_array($data['additional_image_link'])
            ? array_values(array_filter($data['additional_image_link'], 'is_string'))
            : [];
        if ($images) {
            $item['additional_image_link'] = $images;
        }

        return $item;
    }

    /**
     * Plain text of a description; template directives are dropped, not rendered
     *
     * @param string $html
     * @return string
     */
    private function plain(string $html): string
    {
        return $this->text->plain((string) preg_replace('/\{\{.*?\}\}/s', ' ', $html));
    }

    /**
     * Read a value as a string
     *
     * @param array $data
     * @param string $key
     * @return string
     */
    private function string(array $data, string $key): string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
    }
}
