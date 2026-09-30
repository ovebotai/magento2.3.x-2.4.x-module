<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Controller\Feed;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Ovebot\Chat\Controller\Feed\Index;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\Feed\DomainLimit;
use Ovebot\Chat\Model\Feed\FeedAccess;
use Ovebot\Chat\Model\Feed\FeedCache;
use Ovebot\Chat\Model\Feed\FeedFileWriter;
use Ovebot\Chat\Model\Feed\FileResponse;
use Ovebot\Chat\Model\Feed\FileResponseFactory;
use Ovebot\Chat\Model\Feed\ProductFeedBuilder;
use Ovebot\Chat\Model\StoreContext;
use Ovebot\Chat\Model\StoreContext\StoreView;
use Ovebot\Chat\Model\StoreEmulator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which feed a request gets: the kept file, or a sample of the first items built for it
 */
class IndexTest extends TestCase
{
    /**
     * @var array request parameters
     */
    private $params = [];

    /**
     * @var string domain of the shop
     */
    private $domain = '';

    /**
     * @var array what was sent: 'file' for the kept feed, or ['sample', limit, random]
     */
    private $sent = [];

    protected function setUp(): void
    {
        $this->params = ['hash' => str_repeat('a', 32)];
        $this->domain = 'www.shop-test.ro';
        $this->sent = [];
    }

    private function send(): array
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(function ($name) {
            return isset($this->params[$name]) ? $this->params[$name] : null;
        });
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $connections = $this->createMock(ConnectionRepository::class);
        $connections->method('get')->willReturn($this->createMock(Connection::class));
        $feedAccess = $this->createMock(FeedAccess::class);
        $feedAccess->method('isAllowed')->willReturn(true);

        $feedCache = $this->createMock(FeedCache::class);
        $feedCache->method('path')->willReturnCallback(function () {
            $this->sent[] = 'file';

            return 'ovebot_chat/feed/feed-abc.json';
        });
        $writer = $this->createMock(FeedFileWriter::class);
        $writer->method('getDirectory')->willReturn($this->createMock(WriteInterface::class));
        $writer->method('encode')->willReturn('[]');

        $fileResponseFactory = $this->getMockBuilder(FileResponseFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $fileResponseFactory->method('create')->willReturn($this->createMock(FileResponse::class));

        $json = $this->createMock(Json::class);
        $json->method('setHttpResponseCode')->willReturnSelf();
        $json->method('setHeader')->willReturnSelf();
        $json->method('setJsonData')->willReturnSelf();
        $json->method('setData')->willReturnSelf();
        $jsonFactory = $this->getMockBuilder(JsonFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $jsonFactory->method('create')->willReturn($json);

        $builder = $this->createMock(ProductFeedBuilder::class);
        $builder->method('sample')->willReturnCallback(function ($limit, $random) {
            $this->sent[] = ['sample', $limit, $random];

            return (function () {
                yield from [];
            })();
        });
        $emulator = $this->createMock(StoreEmulator::class);
        $emulator->method('run')->willReturnCallback(function ($callback) {
            return $callback();
        });

        $storeView = $this->createMock(StoreView::class);
        $storeView->method('getDomain')->willReturnCallback(function () {
            return $this->domain;
        });
        $storeContext = $this->createMock(StoreContext::class);
        $storeContext->method('get')->willReturn($storeView);

        $controller = new Index(
            $context,
            $connections,
            $feedAccess,
            $feedCache,
            $writer,
            $fileResponseFactory,
            $jsonFactory,
            $this->createMock(LoggerInterface::class),
            $builder,
            $emulator,
            new DomainLimit(['.shops.ro'], 100),
            $storeContext
        );
        $controller->execute();

        return $this->sent;
    }

    public function testAnyOtherShopGetsTheWholeFeed()
    {
        $this->assertSame(['file'], $this->send());
    }

    public function testATestShopGetsTheFirstHundredItems()
    {
        $this->domain = 'demo.shops.ro';

        // in order, never at random: the same items at every request
        $this->assertSame([['sample', 100, false]], $this->send());
    }

    public function testLimitZeroGivesTheWholeFeedOnATestShop()
    {
        $this->domain = 'demo.shops.ro';
        $this->params['limit'] = '0';

        $this->assertSame(['file'], $this->send());
    }

    public function testAGivenLimitWinsOnATestShop()
    {
        $this->domain = 'demo.shops.ro';
        $this->params['limit'] = '5';

        $this->assertSame([['sample', 5, false]], $this->send());
    }

    public function testRandomStaysRandomOnATestShop()
    {
        $this->domain = 'demo.shops.ro';
        $this->params['random'] = 'true';

        $this->assertSame([['sample', 10, true]], $this->send());
    }

    public function testAShopWithoutAStorefrontGetsTheWholeFeed()
    {
        $this->domain = '';

        $this->assertSame(['file'], $this->send());
    }
}
