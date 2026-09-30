<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Feed;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface as DirectoryWrite;
use Magento\Framework\Filesystem\File\WriteInterface as FileWrite;
use Ovebot\Chat\Model\Feed\FeedFileWriter;
use Ovebot\Chat\Model\Security\Random;
use PHPUnit\Framework\TestCase;

class FeedFileWriterTest extends TestCase
{
    private const NAME = 'ovebot_chat/feed/feed-00112233aabbccdd.json';

    private const UNFINISHED = 'ovebot_chat/feed/feed-00112233aabbccdd.part';

    /**
     * @var array path => content
     */
    private $files = [];

    /**
     * @var array path => last change, unix time
     */
    private $times = [];

    /**
     * @var string[] what happened to the files, in order
     */
    private $steps = [];

    /**
     * @var bool whether a finished feed can get its name
     */
    private $renames = true;

    protected function setUp(): void
    {
        $this->files = [];
        $this->times = [];
        $this->steps = [];
        $this->renames = true;
    }

    private function writer(): FeedFileWriter
    {
        $directory = $this->createMock(DirectoryWrite::class);
        // a search is remembered by Magento until the process ends: the folder must be read every time
        $directory->expects($this->never())->method('search');
        $directory->method('isExist')->willReturnCallback(function ($path) {
            return $path === FeedFileWriter::FOLDER ? (bool) $this->files : isset($this->files[$path]);
        });
        $directory->method('read')->willReturnCallback(function ($path) {
            $this->assertSame(FeedFileWriter::FOLDER, $path);

            return array_keys($this->files);
        });
        $directory->method('stat')->willReturnCallback(function ($path) {
            if (!isset($this->files[$path])) {
                throw new FileSystemException(__('Cannot gather stats! %1', $path));
            }

            return ['mtime' => $this->times[$path], 'size' => strlen($this->files[$path])];
        });
        $directory->method('delete')->willReturnCallback(function ($path) {
            $this->steps[] = 'delete ' . $path;
            unset($this->files[$path], $this->times[$path]);

            return true;
        });
        $directory->method('renameFile')->willReturnCallback(function ($path, $newPath) {
            $this->steps[] = 'rename ' . $path . ' ' . $newPath;
            if (!$this->renames) {
                throw new FileSystemException(__('The file cannot be renamed'));
            }
            $this->files[$newPath] = $this->files[$path];
            $this->times[$newPath] = $this->times[$path];
            unset($this->files[$path], $this->times[$path]);

            return true;
        });
        $directory->method('openFile')->willReturnCallback(function ($path, $mode) {
            $this->steps[] = 'open ' . $path . ' ' . $mode;
            $this->files[$path] = '';
            $this->times[$path] = time();

            $stream = $this->createMock(FileWrite::class);
            $stream->method('write')->willReturnCallback(function ($data) use ($path) {
                $this->files[$path] .= $data;

                return strlen($data);
            });
            $stream->method('close')->willReturnCallback(function () use ($path) {
                $this->steps[] = 'close ' . $path;

                return true;
            });

            return $stream;
        });

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::VAR_DIR)->willReturn($directory);

        $random = $this->createMock(Random::class);
        $random->method('hex')->with(8)->willReturn('00112233aabbccdd');

        return new FeedFileWriter($filesystem, $random);
    }

    private function items(array $items): \Generator
    {
        foreach ($items as $item) {
            yield $item;
        }
    }

    private function file(string $name, int $age, string $content = '[]'): string
    {
        $path = FeedFileWriter::FOLDER . '/' . $name;
        $this->files[$path] = $content;
        $this->times[$path] = time() - $age;

        return $path;
    }

    public function testItemsAreWrittenAsOneJsonArray()
    {
        $items = [
            [
                'ref' => 'A-1',
                'name' => 'Cană "mare" țărănească',
                'url' => 'https://shop.test/cana.html',
                'price' => 12.5,
            ],
            ['ref' => 'A-2', 'quantity' => null, 'attributes' => (object) []],
        ];

        $path = $this->writer()->write($this->items($items));

        $this->assertSame(self::NAME, $path);
        $this->assertSame(
            '[{"ref":"A-1","name":"Cană \"mare\" țărănească","url":"https://shop.test/cana.html","price":12.5},'
            . '{"ref":"A-2","quantity":null,"attributes":{}}]',
            $this->files[$path]
        );
    }

    public function testFeedGetsItsNameOnlyWhenItIsComplete()
    {
        $this->writer()->write($this->items([['ref' => 'A-1']]));

        $this->assertSame(
            [
                'open ' . self::UNFINISHED . ' w',
                'close ' . self::UNFINISHED,
                'rename ' . self::UNFINISHED . ' ' . self::NAME,
            ],
            $this->steps
        );
        $this->assertSame([self::NAME], array_keys($this->files));
    }

    public function testEmptyCatalogGivesAnEmptyArray()
    {
        $path = $this->writer()->write($this->items([]));

        $this->assertSame('[]', $this->files[$path]);
    }

    public function testFailureWhileReadingTheCatalogLeavesNoFileBehind()
    {
        $failing = (function () {
            yield ['ref' => 'A-1'];

            throw new \RuntimeException('index table is locked');
        })();

        try {
            $this->writer()->write($failing);
            $this->fail('write() did not raise');
        } catch (\RuntimeException $e) {
            $this->assertSame('index table is locked', $e->getMessage());
        }

        $this->assertSame([], $this->files);
        $this->assertSame(
            ['open ' . self::UNFINISHED . ' w', 'close ' . self::UNFINISHED, 'delete ' . self::UNFINISHED],
            $this->steps
        );
    }

    public function testFailureWhileWritingKeepsTheFeedThatWasThere()
    {
        $old = $this->file('feed-old.json', 3600, '[{"ref":"A-1"}]');
        $failing = (function () {
            yield ['ref' => 'A-1'];

            throw new \RuntimeException('index table is locked');
        })();

        try {
            $this->writer()->write($failing);
            $this->fail('write() did not raise');
        } catch (\RuntimeException $e) {
            $this->assertSame([$old], array_keys($this->files));
        }
    }

    public function testFeedThatCannotGetItsNameIsRemoved()
    {
        $this->renames = false;

        try {
            $this->writer()->write($this->items([['ref' => 'A-1']]));
            $this->fail('write() did not raise');
        } catch (FileSystemException $e) {
            $this->assertSame([], $this->files);
        }
    }

    public function testLatestIsTheFeedWrittenLast()
    {
        $this->file('feed-older.json', 7200);
        $recent = $this->file('feed-recent.json', 60);
        $this->file('feed-old.json', 3600);

        $latest = $this->writer()->latest();

        $this->assertSame($recent, $latest['path']);
        $this->assertSame($this->times[$recent], $latest['time']);
    }

    public function testUnfinishedFeedIsNeverTheLatest()
    {
        $old = $this->file('feed-old.json', 3600);
        $this->file('feed-running.part', 5, '[{"ref":"A-1"}');

        $this->assertSame($old, $this->writer()->latest()['path']);
    }

    public function testNoFeedGivesNoLatest()
    {
        $this->file('feed-running.part', 5, '[');
        $this->file('notes.json', 5);

        $this->assertNull($this->writer()->latest());
    }

    public function testFeedWrittenMeanwhileIsSeen()
    {
        $writer = $this->writer();
        $this->assertNull($writer->latest());

        $written = $this->file('feed-new.json', 0);

        $this->assertSame($written, $writer->latest()['path']);
    }

    public function testCleaningLeavesOnlyTheFeedToKeep()
    {
        $this->file('feed-old.json', 3600);
        $this->file('feed-dead.part', 86400, '[');
        $new = $this->file('feed-new.json', 0);
        $foreign = $this->file('notes.json', 5);

        $this->writer()->clean($new);

        $this->assertSame([$new, $foreign], array_keys($this->files));
    }

    public function testCleaningWithNothingToKeepRemovesEveryFeedFile()
    {
        $this->file('feed-old.json', 3600);
        $this->file('feed-dead.part', 86400, '[');

        $this->writer()->clean();

        $this->assertSame([], $this->files);
    }

    public function testBrokenCharactersDoNotCostTheItem()
    {
        $json = $this->writer()->encode(['ref' => 'A-1', 'name' => "Can\xC4 mare"]);

        $this->assertNotSame('', $json);
        $this->assertSame('A-1', json_decode($json, true)['ref']);
    }

    public function testThePrettyFormIsIndentedAndKeepsTheSameData()
    {
        $items = [['ref' => 'A-1', 'name' => 'Cană mare', 'url' => 'https://shop.test/cana']];

        $compact = $this->writer()->encode($items);
        $pretty = $this->writer()->encode($items, true);

        $this->assertStringNotContainsString("\n", $compact);
        $this->assertStringContainsString("\n    {", $pretty);
        // the same flags otherwise: no escaped slashes or letters
        $this->assertStringContainsString('"Cană mare"', $pretty);
        $this->assertStringContainsString('https://shop.test/cana', $pretty);
        $this->assertSame(json_decode($compact, true), json_decode($pretty, true));
    }
}
