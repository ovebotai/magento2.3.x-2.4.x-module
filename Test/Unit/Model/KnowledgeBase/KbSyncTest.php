<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\KnowledgeBase;

use Ovebot\Chat\Model\Api\ErrorParser;
use Ovebot\Chat\Model\Api\Exception\ApiException;
use Ovebot\Chat\Model\Api\Exception\ConnectionException;
use Ovebot\Chat\Model\Api\Response;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\KnowledgeBase\CmsPageProvider;
use Ovebot\Chat\Model\KnowledgeBase\KbSync;
use Ovebot\Chat\Model\StoreContext\StoreView;
use Ovebot\Chat\Model\Util\Text;
use PHPUnit\Framework\TestCase;

class KbSyncTest extends TestCase
{
    private const KB = '/v1/workspaces/my-shop/agents/default/knowledge-base';

    /**
     * @var array calls made to the API: [key, path, body]
     */
    private $calls = [];

    /**
     * @var array "METHOD path" => Response, or a list of them returned in order
     */
    private $responses = [];

    /**
     * @var bool
     */
    private $throwConnection = false;

    /**
     * @var array page id => row given by the page provider
     */
    private $pages = [];

    /**
     * @var array [page id, store id] asked from the page provider
     */
    private $loaded = [];

    protected function setUp(): void
    {
        $this->calls = [];
        $this->responses = [];
        $this->throwConnection = false;
        $this->loaded = [];
        $this->pages = [
            1 => $this->page(1, 'About', '<p>Long enough content here</p>', true, 'about'),
            2 => $this->page(2, 'Delivery', '<p>Delivery info that is long</p>', true, 'delivery'),
            3 => $this->page(3, 'Hidden', '<p>Hidden page content</p>', false, 'hidden'),
            4 => $this->page(4, 'Tiny', '<p></p>', true, 'tiny'),
            5 => $this->page(5, 'Terms', '<p>Terms and conditions text</p>', true, 'terms'),
        ];
    }

    private function page(int $id, string $title, string $content, bool $active, string $identifier): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'content' => $content,
            'active' => $active,
            'url' => 'https://www.shop-test.ro/' . $identifier,
        ];
    }

    private function integration(): Integration
    {
        $storeView = $this->createMock(StoreView::class);
        $storeView->method('getBaseUrl')->willReturn('https://www.shop-test.ro/');

        $integration = $this->createMock(Integration::class);
        $integration->method('getStoreView')->willReturn($storeView);
        $integration->method('getKbApiPath')->willReturn(self::KB);
        $integration->method('apiRequest')->willReturnCallback(function ($method, $path, $body = null) {
            $key = strtoupper($method) . ' ' . preg_replace('/\?.*/', '', $path);
            $this->calls[] = ['key' => $key, 'path' => $path, 'body' => $body];
            if ($this->throwConnection) {
                throw new ConnectionException(__('Ovebot.ai connection error: timeout'));
            }
            if (!isset($this->responses[$key])) {
                return new Response(200, []);
            }
            if (!is_array($this->responses[$key])) {
                return $this->responses[$key];
            }
            $next = array_shift($this->responses[$key]);
            if (!$this->responses[$key]) {
                unset($this->responses[$key]);
            }

            return $next;
        });

        return $integration;
    }

    private function kbSync(): KbSync
    {
        $pages = $this->createMock(CmsPageProvider::class);
        $pages->method('load')->willReturnCallback(function ($pageId) {
            $this->loaded[] = $pageId;

            return isset($this->pages[$pageId]) ? $this->pages[$pageId] : null;
        });

        return new KbSync($pages, new ErrorParser(), new Text());
    }

    private function keys(): array
    {
        return array_map(function ($call) {
            return $call['key'];
        }, $this->calls);
    }

    private function remoteList(array $entries, ?int $total = null): Response
    {
        return new Response(200, ['entries' => $entries, 'total' => $total === null ? count($entries) : $total]);
    }

    public function testSlugAndBody()
    {
        $kbSync = $this->kbSync();

        $this->assertSame('cms-7', $kbSync->slug(7));
        $this->assertSame("Title\n\nBody text", $kbSync->buildBody('Title', '<p>Body text</p>'));
        $this->assertSame('Title', $kbSync->buildBody(' Title ', ''));
        $this->assertSame('Body text', $kbSync->buildBody('', '<p>Body text</p>'));
    }

    public function testNothingToSendMakesNoCall()
    {
        $result = $this->kbSync()->sync($this->integration(), [0, '', 'abc', -0], true);

        $this->assertSame(['failed' => [], 'kb_limit' => '', 'kb_limit_ids' => []], $result);
        $this->assertSame([], $this->calls);
    }

    public function testSlugFoundUpdatesNotFoundCreatesAndTheQuotaDoesNotStopTheRun()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([['id' => 10, 'slug' => 'cms-1']]);
        $this->responses['POST ' . self::KB] = [
            new Response(201, ['id' => 11]),
            new Response(409, ['error' => ['code' => 'kb_limit_reached', 'message' => 'Your plan allows 2 entries.']]),
        ];
        $this->responses['PUT ' . self::KB . '/10'] = new Response(200, []);

        // 2 and 5 are new, 1 has an entry, 3 is disabled, 4 has no text, 99 does not exist, 1 is given twice
        $result = $this->kbSync()->sync($this->integration(), [2, 5, 1, '3', 4, 99, 1], true);

        $this->assertSame('Your plan allows 2 entries.', $result['kb_limit']);
        $this->assertSame([5], $result['kb_limit_ids']);
        $this->assertSame(
            [
                3 => 'Skipped "Hidden" - page is not enabled.',
                4 => 'Skipped "Tiny" - not enough text content to sync (minimum 10 characters).',
            ],
            $result['failed']
        );
        $this->assertSame(
            ['GET ' . self::KB, 'POST ' . self::KB, 'POST ' . self::KB, 'PUT ' . self::KB . '/10'],
            $this->keys()
        );
        $this->assertSame(self::KB . '?page=1&per_page=100', $this->calls[0]['path']);
        $this->assertSame(
            [
                'title' => 'About',
                'body' => "About\n\nLong enough content here",
                'is_active' => true,
                'slug' => 'cms-1',
                'source_url' => 'https://www.shop-test.ro/about',
            ],
            $this->calls[3]['body']
        );
        $this->assertSame('cms-2', $this->calls[1]['body']['slug']);
        $this->assertSame(2, $this->loaded[0]);
    }

    public function testQuotaOnUpdateIsAnOrdinaryFailure()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([['id' => 10, 'slug' => 'cms-1']]);
        $this->responses['PUT ' . self::KB . '/10'] = new Response(409, ['error' => ['message' => 'Limit reached.']]);

        $result = $this->kbSync()->sync($this->integration(), [1], true);

        $this->assertSame('', $result['kb_limit']);
        $this->assertSame([], $result['kb_limit_ids']);
        $this->assertSame(
            [1 => 'Could not sync knowledge base entry for "About". Limit reached.'],
            $result['failed']
        );
    }

    /**
     * @dataProvider quotaAnswers
     */
    public function testQuotaOnCreate(int $status, array $body, string $expected)
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([]);
        $this->responses['POST ' . self::KB] = new Response($status, $body);

        $result = $this->kbSync()->sync($this->integration(), [1], true);

        $this->assertSame($expected, $result['kb_limit']);
        $this->assertSame([1], $result['kb_limit_ids']);
        $this->assertSame([], $result['failed']);
    }

    public static function quotaAnswers(): array
    {
        return [
            'by code' => [422, ['error' => ['code' => 'kb_limit_reached', 'message' => 'No room.']], 'No room.'],
            'by status 409' => [409, [], 'HTTP 409'],
            'by status 402' => [402, ['message' => 'Upgrade your plan.'], 'Upgrade your plan.'],
            'by message' => [400, ['message' => 'Quota exceeded'], 'Quota exceeded'],
        ];
    }

    public function testRefusedCreateThatIsNotAboutTheQuota()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([]);
        $this->responses['POST ' . self::KB] = [
            new Response(422, ['errors' => ['body' => ['Body is not valid.']]]),
            new Response(201, ['id' => 12]),
        ];

        $result = $this->kbSync()->sync($this->integration(), [1, 2], true);

        $this->assertSame(
            [1 => 'Could not sync knowledge base entry for "About". Body is not valid.'],
            $result['failed']
        );
        $this->assertSame([], $result['kb_limit_ids']);
        // the second page still went
        $this->assertSame(['GET ' . self::KB, 'POST ' . self::KB, 'POST ' . self::KB], $this->keys());
    }

    public function testEntryDeletedInTheAccountIsCreatedAgain()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([['id' => 10, 'slug' => 'cms-1']]);
        $this->responses['PUT ' . self::KB . '/10'] = new Response(404, []);
        $this->responses['POST ' . self::KB] = new Response(201, ['id' => 12]);

        $result = $this->kbSync()->sync($this->integration(), [1], true);

        $this->assertSame(['failed' => [], 'kb_limit' => '', 'kb_limit_ids' => []], $result);
        $this->assertSame(['GET ' . self::KB, 'PUT ' . self::KB . '/10', 'POST ' . self::KB], $this->keys());
    }

    public function testDeactivatingAnEntryDeletedInTheAccountCreatesNothing()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([['id' => 10, 'slug' => 'cms-1']]);
        $this->responses['PUT ' . self::KB . '/10'] = new Response(404, []);

        $result = $this->kbSync()->sync($this->integration(), [1], false);

        $this->assertSame(['failed' => [], 'kb_limit' => '', 'kb_limit_ids' => []], $result);
        $this->assertSame(['GET ' . self::KB, 'PUT ' . self::KB . '/10'], $this->keys());
    }

    public function testDeactivatingWithoutAnEntryDoesNothing()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([]);

        $result = $this->kbSync()->sync($this->integration(), [1], false);

        $this->assertSame(['failed' => [], 'kb_limit' => '', 'kb_limit_ids' => []], $result);
        $this->assertSame(['GET ' . self::KB], $this->keys());
    }

    public function testDisabledPageSwitchesItsEntryOff()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([
            ['id' => 30, 'slug' => 'cms-3'],
            ['id' => 40, 'slug' => 'cms-4'],
        ]);

        $result = $this->kbSync()->sync($this->integration(), [3, 4], false);

        $this->assertSame([], $result['failed']);
        $this->assertSame(['GET ' . self::KB, 'PUT ' . self::KB . '/30', 'PUT ' . self::KB . '/40'], $this->keys());
        $this->assertFalse($this->calls[1]['body']['is_active']);
        // the text of a disabled page does not leave the shop again: the title and the slug only
        $this->assertSame("Hidden\n\ncms-3", $this->calls[1]['body']['body']);
        $this->assertSame("Tiny\n\ncms-4", $this->calls[2]['body']['body']);
        foreach ([1, 2] as $call) {
            $this->assertStringNotContainsString('content', json_encode($this->calls[$call]['body']));
        }
    }

    public function testListIsReadPageByPage()
    {
        $this->responses['GET ' . self::KB] = [
            $this->remoteList([['id' => 10, 'slug' => 'cms-9'], ['id' => 11, 'slug' => 'manual-entry']], 3),
            $this->remoteList([['id' => 12, 'slug' => 'cms-1'], ['slug' => 'no-id'], ['id' => 13]], 3),
        ];

        $this->kbSync()->sync($this->integration(), [1], true);

        $this->assertSame(['GET ' . self::KB, 'GET ' . self::KB, 'PUT ' . self::KB . '/12'], $this->keys());
        $this->assertSame(self::KB . '?page=2&per_page=100', $this->calls[1]['path']);
    }

    public function testListThatCannotBeReadStopsTheRun()
    {
        $this->responses['GET ' . self::KB] = new Response(500, ['message' => 'Server error']);

        try {
            $this->kbSync()->sync($this->integration(), [1], true);
            $this->fail('A partial list must not be used.');
        } catch (ApiException $e) {
            $this->assertSame('Could not load knowledge base entries from Ovebot.ai. Server error', $e->getMessage());
            $this->assertSame(500, $e->getCode());
        }

        $this->assertSame(['GET ' . self::KB], $this->keys());
    }

    public function testSecondPageOfTheListThatCannotBeReadStopsTheRun()
    {
        $this->responses['GET ' . self::KB] = [
            $this->remoteList([['id' => 10, 'slug' => 'cms-9']], 2),
            new Response(502, []),
        ];

        $this->expectException(ApiException::class);

        $this->kbSync()->sync($this->integration(), [1], true);
    }

    public function testUnreachableApiStopsTheRun()
    {
        $this->throwConnection = true;

        $this->expectException(ConnectionException::class);

        $this->kbSync()->sync($this->integration(), [1], true);
    }

    public function testDeactivate()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([['id' => 10, 'slug' => 'cms-1']]);
        $kbSync = $this->kbSync();

        $this->assertTrue($kbSync->deactivate($this->integration(), 1, ' <b>About</b> '));
        $this->assertSame(['GET ' . self::KB, 'PUT ' . self::KB . '/10'], $this->keys());
        $this->assertSame(
            [
                'title' => 'About',
                // the content of the page is not sent when its entry is switched off
                'body' => "About\n\ncms-1",
                'is_active' => false,
                'slug' => 'cms-1',
                'source_url' => 'https://www.shop-test.ro/',
            ],
            $this->calls[1]['body']
        );
        // the page provider is not asked: the page may be gone
        $this->assertSame([], $this->loaded);
    }

    public function testDeactivateFillsAShortBodyAndAnEmptyTitle()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([['id' => 10, 'slug' => 'cms-1']]);

        $this->assertTrue($this->kbSync()->deactivate($this->integration(), 1, ''));
        $this->assertSame('cms-1', $this->calls[1]['body']['title']);
        // the slug is repeated up to the minimum of the API
        $this->assertSame('cms-1 cms-1', $this->calls[1]['body']['body']);
    }

    /**
     * @dataProvider inactiveBodies
     */
    public function testInactiveBodyIsNeverShorterThanTheMinimum(string $title, string $slug, string $expected)
    {
        $body = $this->kbSync()->inactiveBody($title, $slug);

        $this->assertSame($expected, $body);
        $this->assertGreaterThanOrEqual(KbSync::MIN_BODY_LENGTH, mb_strlen($body, 'UTF-8'));
    }

    public static function inactiveBodies(): array
    {
        return [
            'title and slug' => ['About us', 'cms-12', "About us\n\ncms-12"],
            'no title' => ['', 'cms-1', 'cms-1 cms-1'],
            'short title' => ['Hi', 'cms-1', "Hi\n\ncms-1 cms-1"],
            // counted in characters, not in bytes
            'short title with diacritics' => ['Șă', 'cms-1', "Șă\n\ncms-1 cms-1"],
        ];
    }

    public function testDeactivateWithoutAnEntry()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([['id' => 10, 'slug' => 'cms-1']]);

        $this->assertFalse($this->kbSync()->deactivate($this->integration(), 77, 'X'));
        $this->assertSame(['GET ' . self::KB], $this->keys());
    }

    public function testDeactivateAnEntryDeletedInTheAccount()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([['id' => 10, 'slug' => 'cms-1']]);
        $this->responses['PUT ' . self::KB . '/10'] = new Response(404, []);

        $this->assertFalse($this->kbSync()->deactivate($this->integration(), 1, 'About'));
    }

    public function testDeactivateRefused()
    {
        $this->responses['GET ' . self::KB] = $this->remoteList([['id' => 10, 'slug' => 'cms-1']]);
        $this->responses['PUT ' . self::KB . '/10'] = new Response(500, []);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Could not sync knowledge base entry for "About". HTTP 500');

        $this->kbSync()->deactivate($this->integration(), 1, 'About');
    }
}
