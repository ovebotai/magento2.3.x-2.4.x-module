<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\KnowledgeBase;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Helper\Page as PageHelper;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Ovebot\Chat\Model\StoreContext;
use Ovebot\Chat\Model\StoreEmulator;
use Ovebot\Chat\Model\Util\Text;

/**
 * CMS pages of the shop: the list of the wizard and the loader used by the knowledge base sync.
 *
 * A page belongs to the shop when it is assigned to the default store view or to all store views (store id 0):
 * these are the pages that open on the URLs sent to Ovebot.ai.
 */
class CmsPageProvider
{
    /**
     * Technical pages, never ticked by default
     */
    private const TECHNICAL_IDENTIFIERS = ['no-route', 'enable-cookies'];

    /**
     * @var PageRepositoryInterface
     */
    private $pageRepository;

    /**
     * @var PageResource
     */
    private $pageResource;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var FilterProvider
     */
    private $filterProvider;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreContext
     */
    private $storeContext;

    /**
     * @var StoreEmulator
     */
    private $storeEmulator;

    /**
     * @var Text
     */
    private $text;

    /**
     * @param PageRepositoryInterface $pageRepository
     * @param PageResource $pageResource
     * @param CollectionFactory $collectionFactory
     * @param FilterProvider $filterProvider
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreContext $storeContext
     * @param StoreEmulator $storeEmulator
     * @param Text $text
     */
    public function __construct(
        PageRepositoryInterface $pageRepository,
        PageResource $pageResource,
        CollectionFactory $collectionFactory,
        FilterProvider $filterProvider,
        ScopeConfigInterface $scopeConfig,
        StoreContext $storeContext,
        StoreEmulator $storeEmulator,
        Text $text
    ) {
        $this->pageRepository = $pageRepository;
        $this->pageResource = $pageResource;
        $this->collectionFactory = $collectionFactory;
        $this->filterProvider = $filterProvider;
        $this->scopeConfig = $scopeConfig;
        $this->storeContext = $storeContext;
        $this->storeEmulator = $storeEmulator;
        $this->text = $text;
    }

    /**
     * Enabled pages of the shop, by title
     *
     * While no selection was saved, every page is ticked, except the technical ones. Afterwards the saved
     * selection is kept.
     *
     * @param int[] $savedIds
     * @return array [['id' => int, 'title' => string, 'url' => string, 'checked' => bool], ...]
     * @throws LocalizedException when the installation has no default store view
     */
    public function listForWizard(array $savedIds): array
    {
        $savedIds = array_map('intval', $savedIds);
        $technical = $this->getTechnicalIdentifiers();

        $collection = $this->collectionFactory->create();
        $collection->addStoreFilter($this->storeContext->get()->getStoreId());
        $collection->addFieldToFilter('is_active', 1);
        $collection->setOrder('title', 'ASC');

        $pages = [];
        foreach ($collection as $page) {
            $pageId = (int) $page->getId();
            $identifier = (string) $page->getIdentifier();
            $pages[] = [
                'id' => $pageId,
                'title' => $this->text->line((string) $page->getTitle()),
                'url' => $this->getUrl($identifier),
                'checked' => $savedIds
                    ? in_array($pageId, $savedIds, true)
                    : !in_array($identifier, $technical, true),
            ];
        }

        return $pages;
    }

    /**
     * One page, with its content rendered as on the storefront
     *
     * @param int $pageId
     * @return array|null ['id', 'title', 'content' (HTML), 'active', 'url']; null when the page does not exist or
     *                    does not belong to the shop
     * @throws LocalizedException when the installation has no default store view
     */
    public function load(int $pageId): ?array
    {
        try {
            $page = $this->pageRepository->getById($pageId);
        } catch (LocalizedException $e) {
            return null;
        }

        if (!$this->isAssigned((int) $page->getId())) {
            return null;
        }

        return [
            'id' => (int) $page->getId(),
            'title' => $this->text->line((string) $page->getTitle()),
            'content' => $this->render((string) $page->getContent()),
            'active' => (bool) $page->isActive(),
            'url' => $this->getUrl((string) $page->getIdentifier()),
        ];
    }

    /**
     * Whether a page is assigned to the default store view or to all store views; read from the database
     *
     * @param int $pageId
     * @return bool
     * @throws LocalizedException when the installation has no default store view
     */
    public function isAssigned(int $pageId): bool
    {
        $storeId = $this->storeContext->get()->getStoreId();
        $storeIds = array_map('intval', (array) $this->pageResource->lookupStoreIds($pageId));

        return in_array(0, $storeIds, true) || in_array($storeId, $storeIds, true);
    }

    /**
     * Turn the directives and the Page Builder content of a page into HTML, as the storefront does
     *
     * @param string $html
     * @return string
     */
    public function render(string $html): string
    {
        if ($html === '') {
            return '';
        }

        try {
            // widgets and blocks need the storefront theme and language, also when the call comes from admin
            return (string) $this->storeEmulator->run(function () use ($html) {
                return $this->filter($html);
            });
        } catch (\Throwable $e) {
            // a broken widget must not stop the sync: the text around the directives is still worth sending
            return (string) preg_replace('/\{\{.*?\}\}/s', ' ', $html);
        }
    }

    /**
     * Public URL of a page; the home page answers on the base URL
     *
     * @param string $identifier
     * @return string
     * @throws LocalizedException when the installation has no default store view
     */
    public function getUrl(string $identifier): string
    {
        $storeView = $this->storeContext->get();

        if ($identifier === '' || $identifier === $this->configIdentifier(PageHelper::XML_PATH_HOME_PAGE)) {
            return $storeView->getBaseUrl();
        }

        return $storeView->getCmsPageUrl($identifier);
    }

    /**
     * Run the page filter of Magento_Cms
     *
     * @param string $html
     * @return string
     * @throws \Exception
     */
    private function filter(string $html): string
    {
        $filter = $this->filterProvider->getPageFilter();
        $hasStore = method_exists($filter, 'setStoreId');

        if ($hasStore) {
            $filter->setStoreId($this->storeContext->get()->getStoreId());
        }

        try {
            return (string) $filter->filter($html);
        } finally {
            if ($hasStore) {
                // the filter is shared: left as it was, it reads the store view of the request again
                $filter->setStoreId(null);
            }
        }
    }

    /**
     * Identifiers of the "404" and "enable cookies" pages: the usual ones and those set in the configuration
     *
     * @return string[]
     */
    private function getTechnicalIdentifiers(): array
    {
        $identifiers = self::TECHNICAL_IDENTIFIERS;
        foreach ([PageHelper::XML_PATH_NO_ROUTE_PAGE, PageHelper::XML_PATH_NO_COOKIES_PAGE] as $path) {
            $identifiers[] = $this->configIdentifier($path);
        }

        return array_values(array_unique(array_filter($identifiers, 'strlen')));
    }

    /**
     * Page identifier kept in the configuration of the default store view
     *
     * The value may be "identifier|page id".
     *
     * @param string $path
     * @return string
     */
    private function configIdentifier(string $path): string
    {
        $value = $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $this->storeContext->get()->getStoreId()
        );

        return is_scalar($value) ? trim((string) strtok((string) $value, '|')) : '';
    }
}
