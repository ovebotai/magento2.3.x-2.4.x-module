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

use Magento\Framework\Lock\LockManagerInterface;
use Ovebot\Chat\Model\StoreEmulator;

/**
 * The feed file a request is answered with.
 *
 * A feed is written by the first request that finds none, or only one that is too old, and is kept for the
 * requests that follow. Writing does not depend on the caller: when Ovebot.ai gives up waiting, the feed is
 * finished anyway and is there when it asks again.
 *
 * One request writes at a time. The others get the old feed meanwhile, or wait when there is none.
 */
class FeedCache
{
    /**
     * Seconds a feed is served for before another one is written
     */
    public const MAX_AGE = 1800;

    /**
     * Seconds a request waits for the feed another request is writing, when there is no older one to send
     */
    public const WAIT = 60;

    private const LOCK = 'ovebot_chat_feed';

    /**
     * @var FeedFileWriter
     */
    private $files;

    /**
     * @var ProductFeedBuilder
     */
    private $feedBuilder;

    /**
     * @var StoreEmulator
     */
    private $storeEmulator;

    /**
     * @var LockManagerInterface
     */
    private $lockManager;

    /**
     * @param FeedFileWriter $files
     * @param ProductFeedBuilder $feedBuilder
     * @param StoreEmulator $storeEmulator
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        FeedFileWriter $files,
        ProductFeedBuilder $feedBuilder,
        StoreEmulator $storeEmulator,
        LockManagerInterface $lockManager
    ) {
        $this->files = $files;
        $this->feedBuilder = $feedBuilder;
        $this->storeEmulator = $storeEmulator;
        $this->lockManager = $lockManager;
    }

    /**
     * Path of the feed to send, written now when there is none to reuse
     *
     * @return string|null relative to var/; null when another request is writing the first feed and it did not
     *                     get ready in time
     * @throws \Exception when the feed cannot be written
     */
    public function path(): ?string
    {
        $latest = $this->files->latest();
        if ($this->isFresh($latest)) {
            return $latest['path'];
        }

        if (!$this->lockManager->lock(self::LOCK, 0)) {
            if ($latest !== null) {
                return $latest['path'];
            }
            if (!$this->lockManager->lock(self::LOCK, self::WAIT)) {
                return null;
            }
        }

        try {
            // written by another request while this one was waiting for its turn
            $latest = $this->files->latest();
            if ($this->isFresh($latest)) {
                return $latest['path'];
            }

            return $this->write($latest);
        } finally {
            $this->lockManager->unlock(self::LOCK);
        }
    }

    /**
     * Write a feed and leave it as the only feed file
     *
     * @param array|null $old the feed that is replaced, kept until the new one is complete
     * @return string path relative to var/
     * @throws \Exception
     */
    private function write(?array $old): string
    {
        // what a request that died left behind
        $this->files->clean($old !== null ? $old['path'] : '');

        // the generator is consumed inside: names, URLs, prices and stock need the storefront around them
        $path = $this->storeEmulator->run(function () {
            return $this->files->write($this->feedBuilder->iterate());
        });

        $this->files->clean($path);

        return $path;
    }

    /**
     * Whether a feed is recent enough to be sent as it is
     *
     * @param array|null $file {path, time}
     * @return bool
     */
    private function isFresh(?array $file): bool
    {
        return $file !== null && $file['time'] > time() - self::MAX_AGE;
    }
}
