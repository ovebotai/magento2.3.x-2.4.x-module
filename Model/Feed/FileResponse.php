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

use Magento\Framework\App\PageCache\NotCacheableInterface;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\ReadInterface;

/**
 * HTTP answer that sends a file piece by piece, so a large feed never sits in memory. The file is left in place.
 *
 * The constructor is the one of the parent, on purpose: its arguments differ between Magento versions. The file
 * is given through setFile().
 */
class FileResponse extends Http implements NotCacheableInterface
{
    private const CHUNK = 8192;

    /**
     * @var ReadInterface|null
     */
    private $stream;

    /**
     * Set the file to send
     *
     * The file is opened here, not when it is sent: the feed may be replaced by a newer one in the meantime,
     * and an open file can still be read after it was removed.
     *
     * @param WriteInterface $directory
     * @param string $path relative to the directory
     * @return $this
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function setFile(WriteInterface $directory, string $path)
    {
        $this->stream = $directory->openFile($path, 'r');

        $stat = $directory->stat($path);
        if (isset($stat['size'])) {
            $this->setHeader('Content-Length', (string) (int) $stat['size'], true);
        }

        return $this;
    }

    /**
     * Send the headers, then the file
     *
     * @return void
     */
    public function sendResponse()
    {
        if ($this->stream === null) {
            parent::sendResponse();

            return;
        }

        try {
            $this->sendHeaders();
            if (!$this->request->isHead()) {
                while (!$this->stream->eof()) {
                    // written straight to the output, as Magento does for its own file downloads
                    // phpcs:ignore Magento2.Security.LanguageConstruct.DirectOutput
                    echo $this->stream->read(self::CHUNK);
                }
            }
        } finally {
            $this->stream->close();
            $this->stream = null;
        }
    }
}
