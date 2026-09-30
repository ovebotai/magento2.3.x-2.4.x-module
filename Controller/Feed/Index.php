<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Controller\Feed;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\Feed\DomainLimit;
use Ovebot\Chat\Model\Feed\FeedAccess;
use Ovebot\Chat\Model\Feed\FeedCache;
use Ovebot\Chat\Model\Feed\FeedFileWriter;
use Ovebot\Chat\Model\Feed\FileResponseFactory;
use Ovebot\Chat\Model\Feed\ProductFeedBuilder;
use Ovebot\Chat\Model\StoreContext;
use Ovebot\Chat\Model\StoreEmulator;
use Psr\Log\LoggerInterface;

/**
 * The product feed read by Ovebot.ai: GET ovebot/feed/index?hash=..
 *
 * The hash is the key of the feed. The items are the ones of the default store view, whatever store view
 * Magento reads the request as. The feed is written by the first request and kept for the next ones (FeedCache).
 *
 * Not documented, for looking at the feed by hand: "limit=N" gives the first N items, "random=true" items of
 * products picked at random (10 without a limit). A sample is built for the request and is not kept.
 *
 * On the test shops of the authors (DomainLimit, set in di.xml) a feed asked for without "limit" is such a sample
 * of the first items; "limit=0" gives the whole feed there.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Index extends Action implements HttpGetActionInterface
{
    /**
     * Name of the file when the feed is opened in a browser
     */
    private const FILE_NAME = 'ovebot-feed.json';

    /**
     * Most items a sample gives
     */
    private const SAMPLE_MAX = 500;

    /**
     * Items of a random sample without a limit
     */
    private const SAMPLE_DEFAULT = 10;

    /**
     * @var ConnectionRepository
     */
    private $connections;

    /**
     * @var FeedAccess
     */
    private $feedAccess;

    /**
     * @var FeedCache
     */
    private $feedCache;

    /**
     * @var FeedFileWriter
     */
    private $feedFileWriter;

    /**
     * @var FileResponseFactory
     */
    private $fileResponseFactory;

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ProductFeedBuilder
     */
    private $feedBuilder;

    /**
     * @var StoreEmulator
     */
    private $storeEmulator;

    /**
     * @var DomainLimit
     */
    private $domainLimit;

    /**
     * @var StoreContext
     */
    private $storeContext;

    /**
     * @param Context $context
     * @param ConnectionRepository $connections
     * @param FeedAccess $feedAccess
     * @param FeedCache $feedCache
     * @param FeedFileWriter $feedFileWriter
     * @param FileResponseFactory $fileResponseFactory
     * @param JsonFactory $resultJsonFactory
     * @param LoggerInterface $logger
     * @param ProductFeedBuilder $feedBuilder
     * @param StoreEmulator $storeEmulator
     * @param DomainLimit $domainLimit
     * @param StoreContext $storeContext
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        ConnectionRepository $connections,
        FeedAccess $feedAccess,
        FeedCache $feedCache,
        FeedFileWriter $feedFileWriter,
        FileResponseFactory $fileResponseFactory,
        JsonFactory $resultJsonFactory,
        LoggerInterface $logger,
        ProductFeedBuilder $feedBuilder,
        StoreEmulator $storeEmulator,
        DomainLimit $domainLimit,
        StoreContext $storeContext
    ) {
        parent::__construct($context);
        $this->connections = $connections;
        $this->feedAccess = $feedAccess;
        $this->feedCache = $feedCache;
        $this->feedFileWriter = $feedFileWriter;
        $this->fileResponseFactory = $fileResponseFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->logger = $logger;
        $this->feedBuilder = $feedBuilder;
        $this->storeEmulator = $storeEmulator;
        $this->domainLimit = $domainLimit;
        $this->storeContext = $storeContext;
    }

    /**
     * Send the feed
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $given = $this->getRequest()->getParam('hash');

        // 403, not 401: Ovebot.ai reads "switched off", not "wrong credentials"
        if (!$this->feedAccess->isAllowed($this->connections->get(), is_scalar($given) ? (string) $given : '')) {
            return $this->error(403, 'Forbidden');
        }

        $sampleSize = $this->sampleSize();
        if ($sampleSize > 0) {
            return $this->sample($sampleSize, $this->isRandom());
        }

        try {
            $path = $this->feedCache->path();
            if ($path === null) {
                // another request is writing the first feed; Ovebot.ai asks again later
                return $this->error(503, 'Feed is being generated', ['Retry-After' => (string) FeedCache::WAIT]);
            }

            $response = $this->fileResponseFactory->create();
            $response->setFile($this->feedFileWriter->getDirectory(), $path);
        } catch (\Exception $e) {
            $this->logger->warning('Product feed failed: ' . $e->getMessage());

            return $this->error(500, 'Feed could not be generated');
        }

        $response->setHttpResponseCode(200);
        foreach ($this->headers() as $name => $value) {
            $response->setHeader($name, $value, true);
        }
        // a browser saves the file instead of drawing it: a feed of 100+ MB crashes the tab. Ovebot.ai ignores it.
        $response->setHeader('Content-Disposition', 'attachment; filename="' . self::FILE_NAME . '"', true);

        return $response;
    }

    /**
     * A few items, built now, indented and shown by the browser
     *
     * @param int $limit
     * @param bool $random
     * @return \Magento\Framework\Controller\Result\Json
     */
    private function sample(int $limit, bool $random)
    {
        try {
            // the generator is consumed inside: names, URLs, prices and stock need the storefront around them
            $items = $this->storeEmulator->run(function () use ($limit, $random) {
                return iterator_to_array($this->feedBuilder->sample($limit, $random), false);
            });
        } catch (\Exception $e) {
            $this->logger->warning('Product feed sample failed: ' . $e->getMessage());

            return $this->error(500, 'Feed could not be generated');
        }

        $result = $this->resultJsonFactory->create();
        $result->setHttpResponseCode(200);
        foreach ($this->headers() as $name => $value) {
            $result->setHeader($name, $value, true);
        }

        return $result->setJsonData($this->feedFileWriter->encode($items, true));
    }

    /**
     * Items asked for with "limit"; 0 for the whole feed
     *
     * Without "limit", the domain of the shop may set one (DomainLimit): the first items, in the order of the feed.
     *
     * @return int
     */
    private function sampleSize(): int
    {
        $param = $this->getRequest()->getParam('limit');
        if ($param === null && !$this->isRandom()) {
            return min($this->domainLimit(), self::SAMPLE_MAX);
        }

        $limit = is_scalar($param) && ctype_digit((string) $param) ? (int) $param : 0;
        if ($limit === 0 && $this->isRandom()) {
            $limit = self::SAMPLE_DEFAULT;
        }

        return min($limit, self::SAMPLE_MAX);
    }

    /**
     * Most items of the feed on the domain of the shop; 0 for no limit
     *
     * @return int
     */
    private function domainLimit(): int
    {
        try {
            return $this->domainLimit->forDomain($this->storeContext->get()->getDomain());
        } catch (\Exception $e) {
            // no default store view: the whole feed, as everywhere else
            return 0;
        }
    }

    /**
     * Whether "random" was asked for
     *
     * @return bool
     */
    private function isRandom(): bool
    {
        $random = $this->getRequest()->getParam('random');

        return is_scalar($random) && in_array(strtolower((string) $random), ['1', 'true', 'yes'], true);
    }

    /**
     * Error answer in JSON
     *
     * @param int $status
     * @param string $message
     * @param string[] $headers added to the ones of every answer
     * @return \Magento\Framework\Controller\Result\Json
     */
    private function error(int $status, string $message, array $headers = [])
    {
        $result = $this->resultJsonFactory->create();
        $result->setHttpResponseCode($status);
        foreach ($headers + $this->headers() as $name => $value) {
            $result->setHeader($name, $value, true);
        }

        return $result->setData(['error' => $message]);
    }

    /**
     * Headers of every answer: never cached, never indexed
     *
     * @return string[]
     */
    private function headers(): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
