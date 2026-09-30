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

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Ovebot\Chat\Model\Security\Random;

/**
 * Keeps the feed files under var/: writes a feed item by item, as one JSON array, finds the last feed written
 * and removes the other files.
 *
 * A feed is written under the name of an unfinished file and gets the name of a feed only when it is complete:
 * an error while reading the catalog leaves no feed behind, and a feed that is found is never half a feed.
 */
class FeedFileWriter
{
    public const FOLDER = 'ovebot_chat/feed';

    private const FINISHED = '.json';

    private const UNFINISHED = '.part';

    /**
     * Every file of the feed, finished or not
     */
    private const PATTERN = '#/feed-[^/]+\.(json|part)\z#';

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Random
     */
    private $random;

    /**
     * @param Filesystem $filesystem
     * @param Random $random
     */
    public function __construct(Filesystem $filesystem, Random $random)
    {
        $this->filesystem = $filesystem;
        $this->random = $random;
    }

    /**
     * The var/ directory, where the feed files are written
     *
     * @return WriteInterface
     * @throws FileSystemException
     */
    public function getDirectory(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    /**
     * Write the items
     *
     * @param iterable $items
     * @return string path of the feed, relative to var/
     * @throws \Exception what the items raise while they are read, or when the file cannot be written
     */
    public function write(iterable $items): string
    {
        $directory = $this->getDirectory();

        $name = self::FOLDER . '/feed-' . $this->random->hex(8);
        $unfinished = $name . self::UNFINISHED;
        $stream = $directory->openFile($unfinished, 'w');

        try {
            $stream->write('[');
            $separator = '';
            foreach ($items as $item) {
                $json = $this->encode($item);
                if ($json !== '') {
                    $stream->write($separator . $json);
                    $separator = ',';
                }
            }
            $stream->write(']');
            $stream->close();
        } catch (\Exception $e) {
            $stream->close();
            $this->delete($unfinished);

            throw $e;
        }

        try {
            $directory->renameFile($unfinished, $name . self::FINISHED);
        } catch (\Exception $e) {
            $this->delete($unfinished);

            throw $e;
        }

        return $name . self::FINISHED;
    }

    /**
     * The last feed written
     *
     * @return array|null {path: relative to var/, time: unix time of the last write}; null when there is none
     */
    public function latest(): ?array
    {
        $latest = null;

        foreach ($this->files() as $path) {
            if (substr($path, -strlen(self::FINISHED)) !== self::FINISHED) {
                continue;
            }
            try {
                $stat = $this->getDirectory()->stat($path);
            } catch (FileSystemException $e) {
                // removed in the meantime by the request that wrote a newer feed
                continue;
            }
            $time = isset($stat['mtime']) ? (int) $stat['mtime'] : 0;
            if ($latest === null || $time > $latest['time']) {
                $latest = ['path' => $path, 'time' => $time];
            }
        }

        return $latest;
    }

    /**
     * Remove the feed files, finished or not, except one
     *
     * To be called by the request that holds the lock of the feed: an unfinished file that is not its own
     * belongs to a request that died.
     *
     * @param string $keep path relative to var/; empty to remove them all
     * @return void
     */
    public function clean(string $keep = '')
    {
        foreach ($this->files() as $path) {
            if ($path !== $keep) {
                $this->delete($path);
            }
        }
    }

    /**
     * Remove a feed file; a file that cannot be removed now goes at the next cleaning
     *
     * @param string $path relative to var/
     * @return void
     */
    public function delete(string $path)
    {
        try {
            $this->getDirectory()->delete($path);
        } catch (FileSystemException $e) {
            return;
        }
    }

    /**
     * JSON of one item; empty when the item cannot be encoded
     *
     * @param mixed $item
     * @param bool $pretty indented, for a person to read
     * @return string
     */
    public function encode($item, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            // a broken character in a description must not cost the whole item
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $json = json_encode($item, $flags);

        return is_string($json) ? $json : '';
    }

    /**
     * Paths of the feed files, relative to var/
     *
     * The folder is read, not searched: Magento remembers the answer of a search for as long as the process
     * lives, and a feed written in the meantime would not be seen.
     *
     * @return string[]
     */
    private function files(): array
    {
        try {
            $directory = $this->getDirectory();
            if (!$directory->isExist(self::FOLDER)) {
                return [];
            }

            $files = [];
            foreach ($directory->read(self::FOLDER) as $path) {
                if (preg_match(self::PATTERN, '/' . $path)) {
                    $files[] = $path;
                }
            }

            return $files;
        } catch (FileSystemException $e) {
            return [];
        }
    }
}
