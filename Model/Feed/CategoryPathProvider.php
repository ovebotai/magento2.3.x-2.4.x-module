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

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Ovebot\Chat\Model\StoreContext\StoreView;
use Ovebot\Chat\Model\Util\Text;

/**
 * Category of a feed item: the deepest enabled category of the product, as a path "Women > Tops > Jackets".
 *
 * Only the tree of the root category of the store counts, and the root itself is left out. The categories are
 * read once for the whole feed, as plain arrays.
 */
class CategoryPathProvider
{
    public const SEPARATOR = ' > ';

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var Text
     */
    private $text;

    /**
     * @var array|null category id => {name, active, chain}, once read
     */
    private $categories;

    /**
     * @param CollectionFactory $collectionFactory
     * @param Text $text
     */
    public function __construct(CollectionFactory $collectionFactory, Text $text)
    {
        $this->collectionFactory = $collectionFactory;
        $this->text = $text;
    }

    /**
     * Category path of each product
     *
     * @param array $categoryIds product id => category ids
     * @param StoreView $context
     * @return array product id => path; a product without a usable category is left out
     */
    public function forProducts(array $categoryIds, StoreView $context): array
    {
        $categories = $this->categories($context);

        $paths = [];
        foreach ($categoryIds as $productId => $ids) {
            $path = $this->resolve(is_array($ids) ? $ids : [], $categories);
            if ($path !== null) {
                $paths[$productId] = $path;
            }
        }

        return $paths;
    }

    /**
     * Path of the deepest category that can be reached on the storefront
     *
     * A category under a disabled one cannot be reached. Between two categories of the same depth, the one
     * with the lower id wins, so the answer does not change from one run to the next.
     *
     * @param array $categoryIds categories of the product
     * @param array $categories category id => {name: string, active: bool, chain: ids from below the root to itself}
     * @return string|null
     */
    public function resolve(array $categoryIds, array $categories): ?string
    {
        $categoryIds = array_map('intval', $categoryIds);
        sort($categoryIds);

        $best = null;
        $bestDepth = 0;
        foreach ($categoryIds as $categoryId) {
            if (!isset($categories[$categoryId])) {
                continue;
            }

            $names = [];
            foreach ($categories[$categoryId]['chain'] as $id) {
                if (!isset($categories[$id]) || !$categories[$id]['active'] || $categories[$id]['name'] === '') {
                    continue 2;
                }
                $names[] = $categories[$id]['name'];
            }

            if (count($names) > $bestDepth) {
                $bestDepth = count($names);
                $best = implode(self::SEPARATOR, $names);
            }
        }

        return $best;
    }

    /**
     * Categories of the tree of the store, in the language of the storefront
     *
     * @param StoreView $context
     * @return array category id => {name, active, chain}
     */
    private function categories(StoreView $context): array
    {
        if ($this->categories === null) {
            $rootId = (int) $context->getStore()->getRootCategoryId();

            $collection = $this->collectionFactory->create();
            $collection->setStoreId($context->getStoreId());
            $collection->addAttributeToSelect(['name', 'is_active']);
            $collection->addFieldToFilter('path', ['like' => '%/' . $rootId . '/%']);

            $this->categories = [];
            foreach ($collection as $category) {
                $chain = $this->chain((string) $category->getPath(), $rootId);
                if ($chain) {
                    $this->categories[(int) $category->getId()] = [
                        'name' => $this->text->line((string) $category->getName()),
                        'active' => (bool) $category->getIsActive(),
                        'chain' => $chain,
                    ];
                }
            }
        }

        return $this->categories;
    }

    /**
     * Ids of a category path that come after the root category, the category itself included
     *
     * @param string $path for example "1/2/5/18"
     * @param int $rootId
     * @return int[] empty when the path does not pass through the root category
     */
    private function chain(string $path, int $rootId): array
    {
        $ids = array_map('intval', explode('/', $path));
        $position = array_search($rootId, $ids, true);

        return $position === false ? [] : array_values(array_slice($ids, $position + 1));
    }
}
