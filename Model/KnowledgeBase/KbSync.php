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

use Magento\Framework\Exception\LocalizedException;
use Ovebot\Chat\Model\Api\ErrorParser;
use Ovebot\Chat\Model\Api\Exception\ApiException;
use Ovebot\Chat\Model\Api\Exception\OvebotException;
use Ovebot\Chat\Model\Api\Response;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\Util\Text;

/**
 * Brings the knowledge base of an agent in line with the CMS pages, by slug.
 *
 * The API does NOT upsert on create, so the slug "cms-{page id}" is matched here against the list of the agent:
 * slug found, the entry is updated (PUT); not found, it is created (POST), only when activating.
 */
class KbSync
{
    public const SLUG_PREFIX = 'cms-';
    public const MIN_BODY_LENGTH = 10;

    private const QUOTA_CODE = 'kb_limit_reached';
    private const QUOTA_STATUS = 409;

    /**
     * @var CmsPageProvider
     */
    private $pages;

    /**
     * @var ErrorParser
     */
    private $errorParser;

    /**
     * @var Text
     */
    private $text;

    /**
     * @param CmsPageProvider $pages
     * @param ErrorParser $errorParser
     * @param Text $text
     */
    public function __construct(CmsPageProvider $pages, ErrorParser $errorParser, Text $text)
    {
        $this->pages = $pages;
        $this->errorParser = $errorParser;
        $this->text = $text;
    }

    /**
     * Send pages to the knowledge base: the list of the agent is read once, then each page goes on its own
     *
     * A reached quota does NOT stop the run: a page that already has an entry is updated, and updates use no quota.
     *
     * @param Integration $integration
     * @param array $pageIds
     * @param bool $active
     * @return array ['failed' => [page id => message], 'kb_limit' => string, 'kb_limit_ids' => int[]]
     * @throws OvebotException when the list of the agent cannot be read
     */
    public function sync(Integration $integration, array $pageIds, bool $active = true): array
    {
        $failed = [];
        $kbLimit = '';
        $kbLimitIds = [];

        $pageIds = array_values(array_unique(array_filter(array_map('intval', $pageIds))));
        if (!$pageIds) {
            return ['failed' => $failed, 'kb_limit' => $kbLimit, 'kb_limit_ids' => $kbLimitIds];
        }

        $remoteBySlug = $this->fetchRemoteSlugs($integration);

        foreach ($pageIds as $pageId) {
            $slug = $this->slug($pageId);
            $entryId = isset($remoteBySlug[$slug]) ? (int) $remoteBySlug[$slug] : 0;

            try {
                if ($entryId) {
                    $this->updateEntry($integration, $entryId, $pageId, $active, $failed);
                } elseif ($active) {
                    $this->insertEntry($integration, $pageId, $failed);
                }
                // no entry and deactivating: nothing to do
            } catch (QuotaReachedException $e) {
                if ($kbLimit === '') {
                    $kbLimit = $e->getMessage();
                }
                $kbLimitIds[] = $pageId;
            } catch (OvebotException $e) {
                $failed[$pageId] = $e->getMessage();
            }
        }

        return ['failed' => $failed, 'kb_limit' => $kbLimit, 'kb_limit_ids' => $kbLimitIds];
    }

    /**
     * Switch off the entry of a page that can no longer be loaded
     *
     * The page was deleted or moved to other store views; the caller gives the title it holds. The content is not
     * sent: see inactiveBody().
     *
     * @param Integration $integration
     * @param int $pageId
     * @param string $title
     * @return bool true when an entry was found and switched off
     * @throws OvebotException
     */
    public function deactivate(Integration $integration, int $pageId, string $title): bool
    {
        $remoteBySlug = $this->fetchRemoteSlugs($integration);
        $slug = $this->slug($pageId);
        if (!isset($remoteBySlug[$slug])) {
            return false;
        }

        $title = $this->text->line($title);

        $payload = [
            'title' => $title !== '' ? $title : $slug,
            'body' => $this->inactiveBody($title, $slug),
            'is_active' => false,
            'slug' => $slug,
            'source_url' => $integration->getStoreView()->getBaseUrl(),
        ];

        $response = $integration->apiRequest(
            'PUT',
            $integration->getKbApiPath() . '/' . (int) $remoteBySlug[$slug],
            $payload
        );
        if ($response->getStatus() === 404) {
            return false;
        }
        $this->assertSuccess($response, $payload['title']);

        return true;
    }

    /**
     * Slug of the entry of a page
     *
     * @param int $pageId
     * @return string
     */
    public function slug(int $pageId): string
    {
        return self::SLUG_PREFIX . $pageId;
    }

    /**
     * Body of an entry: the title, an empty line, the content as plain text
     *
     * @param string $title
     * @param string $html
     * @return string
     */
    public function buildBody(string $title, string $html): string
    {
        $content = $this->text->plain($html);
        $title = trim($title);

        return trim($title . ($content !== '' ? "\n\n" . $content : ''));
    }

    /**
     * Body of an entry that is switched off: the title and the slug, never the content
     *
     * A page is disabled, deleted or moved away also because its text should no longer be read by anyone, so the
     * text does not leave the shop once more with the message that switches its entry off. The API asks for a
     * body of a minimum length; the slug is repeated until it is reached.
     *
     * @param string $title
     * @param string $slug
     * @return string
     */
    public function inactiveBody(string $title, string $slug): string
    {
        $body = trim(trim($title) . "\n\n" . $slug);
        while ($this->text->length($body) < self::MIN_BODY_LENGTH) {
            $body .= ' ' . $slug;
        }

        return $body;
    }

    /**
     * Update the entry matched by slug
     *
     * @param Integration $integration
     * @param int $entryId
     * @param int $pageId
     * @param bool $active
     * @param array $failed
     * @return void
     * @throws OvebotException
     */
    private function updateEntry(Integration $integration, int $entryId, int $pageId, bool $active, array &$failed)
    {
        $payload = $this->buildPayload($integration, $pageId, $active, $failed);
        if ($payload === null) {
            return;
        }

        $response = $integration->apiRequest('PUT', $integration->getKbApiPath() . '/' . $entryId, $payload);

        // The entry was deleted in the account after the list was read: create it again when activating;
        // when deactivating there is nothing left to switch off.
        if ($response->getStatus() === 404) {
            if ($active) {
                $this->insertEntry($integration, $pageId, $failed);
            }

            return;
        }

        $this->assertSuccess($response, $payload['title']);
    }

    /**
     * Create an entry; only ever called when activating
     *
     * @param Integration $integration
     * @param int $pageId
     * @param array $failed
     * @return void
     * @throws OvebotException
     */
    private function insertEntry(Integration $integration, int $pageId, array &$failed)
    {
        $payload = $this->buildPayload($integration, $pageId, true, $failed);
        if ($payload === null) {
            return;
        }

        $response = $integration->apiRequest('POST', $integration->getKbApiPath(), $payload);

        $this->assertSuccess($response, $payload['title'], true);
    }

    /**
     * Load the page and build what is sent
     *
     * Gives null, with the reason in $failed, when the page should not be sent: disabled, or with too little
     * text for the minimum of the API. A page that is missing or belongs to other store views is skipped silently.
     *
     * @param Integration $integration
     * @param int $pageId
     * @param bool $active
     * @param array $failed
     * @return array|null
     * @throws LocalizedException when the installation has no default store view
     */
    private function buildPayload(Integration $integration, int $pageId, bool $active, array &$failed): ?array
    {
        $page = $this->pages->load($pageId);
        if ($page === null) {
            return null;
        }

        if (!$page['active'] && $active) {
            $failed[$pageId] = (string) __('Skipped "%1" - page is not enabled.', $page['title']);

            return null;
        }

        if ($active) {
            $body = $this->buildBody($page['title'], $page['content']);
            if ($this->text->length($body) < self::MIN_BODY_LENGTH) {
                $failed[$pageId] = (string) __(
                    'Skipped "%1" - not enough text content to sync (minimum 10 characters).',
                    $page['title']
                );

                return null;
            }
        } else {
            $body = $this->inactiveBody($page['title'], $this->slug($pageId));
        }

        return [
            'title' => $page['title'] !== '' ? $page['title'] : $this->slug($pageId),
            'body' => $body,
            'is_active' => $active,
            'slug' => $this->slug($pageId),
            'source_url' => $page['url'],
        ];
    }

    /**
     * Raise an error for a write that did not succeed
     *
     * A reached quota on CREATE becomes a QuotaReachedException, so sync() can go on with the updates.
     *
     * @param Response $response
     * @param string $title
     * @param bool $isCreate
     * @return void
     * @throws OvebotException
     */
    private function assertSuccess(Response $response, string $title, bool $isCreate = false)
    {
        if ($response->isSuccess()) {
            return;
        }

        $message = $this->errorParser->message($response);

        if ($isCreate && $this->isQuota($response)) {
            $body = $response->getBody();
            $own = isset($body['error']['message']) && is_scalar($body['error']['message'])
                ? (string) $body['error']['message']
                : $message;

            throw new QuotaReachedException(__('%1', $own), null, $response->getStatus());
        }

        throw new ApiException(
            __('Could not sync knowledge base entry for "%1". %2', $title, $message),
            null,
            $response->getStatus()
        );
    }

    /**
     * Whether a refused CREATE means the plan has no room for more entries
     *
     * @param Response $response
     * @return bool
     */
    private function isQuota(Response $response): bool
    {
        return $this->errorParser->code($response) === self::QUOTA_CODE
            || $response->getStatus() === self::QUOTA_STATUS
            || $this->errorParser->isQuota($response);
    }

    /**
     * The whole list of the agent, page by page, as slug => entry id
     *
     * @param Integration $integration
     * @return array
     * @throws OvebotException when a page of the list cannot be read: a partial map would create duplicates
     */
    private function fetchRemoteSlugs(Integration $integration): array
    {
        $bySlug = [];
        $page = 1;
        $fetched = 0;

        do {
            $response = $integration->apiRequest(
                'GET',
                $integration->getKbApiPath() . '?' . http_build_query([
                    'page' => $page,
                    'per_page' => Integration::KB_PAGE_SIZE,
                ])
            );

            if (!$response->isSuccess()) {
                throw new ApiException(
                    __(
                        'Could not load knowledge base entries from Ovebot.ai. %1',
                        $this->errorParser->message($response)
                    ),
                    null,
                    $response->getStatus()
                );
            }

            $body = $response->getBody();
            $entries = isset($body['entries']) && is_array($body['entries']) ? $body['entries'] : [];
            foreach ($entries as $entry) {
                if (!isset($entry['id'], $entry['slug']) || !is_scalar($entry['slug'])) {
                    continue;
                }
                $bySlug[(string) $entry['slug']] = (int) $entry['id'];
            }

            $total = isset($body['total']) ? (int) $body['total'] : 0;
            $fetched += count($entries);
            $page++;
        } while ($entries && $fetched < $total && $page <= Integration::KB_MAX_PAGES);

        return $bySlug;
    }
}
