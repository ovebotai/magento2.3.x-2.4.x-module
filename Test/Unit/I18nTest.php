<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The translation files against the code: every text the module translates is in both files, nothing else is,
 * and each translation keeps the placeholders of its text.
 *
 * The texts are read the way Magento reads them at run time: the literal arguments of __() in PHP and templates
 * (joined when the literal is split with "."), and the XML nodes marked with "translate" or "translatable".
 */
class I18nTest extends TestCase
{
    private const LOCALES = ['en_US', 'ro_RO'];

    /**
     * @var string
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testEveryTextOfTheCodeIsInTheEnglishFileAndNothingElse()
    {
        $inCode = $this->phrasesInCode();
        $inFile = array_keys($this->read('en_US'));

        $this->assertSame([], array_values(array_diff(array_keys($inCode), $inFile)), 'Texts without translation');
        $this->assertSame([], array_values(array_diff($inFile, array_keys($inCode))), 'Texts no longer in the code');
    }

    public function testTheEnglishFileTranslatesEachTextToItself()
    {
        foreach ($this->read('en_US') as $phrase => $translation) {
            $this->assertSame($phrase, $translation);
        }
    }

    public function testTheFilesHaveTheSameTextsInTheSameOrder()
    {
        $this->assertSame(array_keys($this->read('en_US')), array_keys($this->read('ro_RO')));
    }

    public function testTranslationsKeepThePlaceholdersAndAreNotEmpty()
    {
        foreach (self::LOCALES as $locale) {
            foreach ($this->read($locale) as $phrase => $translation) {
                $this->assertNotSame('', trim($translation), $locale . ': ' . $phrase);
                $this->assertSame(
                    $this->placeholders($phrase),
                    $this->placeholders($translation),
                    $locale . ': ' . $phrase
                );
            }
        }
    }

    /**
     * Rows of a translation file, text => translation; fails on a malformed row or a text written twice
     *
     * @param string $locale
     * @return array<string, string>
     */
    private function read(string $locale): array
    {
        $file = $this->root . '/i18n/' . $locale . '.csv';
        $this->assertFileExists($file);

        $rows = [];
        $handle = fopen($file, 'r');
        $line = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;
            $this->assertCount(2, $row, $locale . '.csv, line ' . $line);
            $this->assertArrayNotHasKey($row[0], $rows, $locale . '.csv, line ' . $line . ': written twice');
            $rows[$row[0]] = $row[1];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param string $text
     * @return string[] placeholders (%1, %2...), sorted
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/%\d+/', $text, $matches);
        $found = array_unique($matches[0]);
        sort($found);

        return $found;
    }

    /**
     * Texts the module translates, with the files they come from
     *
     * @return array<string, string[]>
     */
    private function phrasesInCode(): array
    {
        $phrases = [];
        foreach ($this->files() as $file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            if ($extension === 'php' || $extension === 'phtml') {
                foreach ($this->phpPhrases($file) as $phrase) {
                    $phrases[$phrase][] = $file;
                }
            } elseif ($extension === 'xml') {
                foreach ($this->xmlPhrases($file) as $phrase) {
                    $phrases[$phrase][] = $file;
                }
            }
        }

        return $phrases;
    }

    /**
     * Module files, without the tests
     *
     * @return string[]
     */
    private function files(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (strpos($path, '/Test/') === false && strpos($path, '/.git') === false) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * First argument of every __() call; fails on an argument that is not made of literals only
     *
     * @param string $file
     * @return string[]
     */
    private function phpPhrases(string $file): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $tokens = token_get_all(file_get_contents($file));
        $count = count($tokens);
        $phrases = [];
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || $tokens[$i][1] !== '__') {
                continue;
            }
            $next = $this->skipBlank($tokens, $i + 1);
            if ($next >= $count || $tokens[$next] !== '(') {
                continue;
            }

            $parts = [];
            for ($j = $this->skipBlank($tokens, $next + 1); $j < $count; $j = $this->skipBlank($tokens, $j + 1)) {
                $token = $tokens[$j];
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $parts[] = $this->literal($token[1]);
                } elseif ($token === ',' || $token === ')') {
                    break;
                } elseif ($token !== '.') {
                    $this->fail('__() with a text that is not a literal in ' . $file . ', line ' . $tokens[$i][2]);
                }
            }
            $phrases[] = implode('', $parts);
        }

        return $phrases;
    }

    /**
     * @param array $tokens
     * @param int $index
     * @return int index of the next token that is not white space or a comment
     */
    private function skipBlank(array $tokens, int $index): int
    {
        $count = count($tokens);
        while ($index < $count && is_array($tokens[$index])
            && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ) {
            $index++;
        }

        return $index;
    }

    /**
     * Value of a PHP string literal without variables
     *
     * @param string $literal
     * @return string
     */
    private function literal(string $literal): string
    {
        $quote = $literal[0];
        $body = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }
        $this->assertStringNotContainsString('$', $body, 'Variable in a translated text: ' . $literal);

        return stripcslashes($body);
    }

    /**
     * Texts of the nodes marked with translate="true", translatable="true" or translate="label comment"
     *
     * @param string $file
     * @return string[]
     */
    private function xmlPhrases(string $file): array
    {
        $xml = simplexml_load_file($file);
        $this->assertNotFalse($xml, 'Invalid XML: ' . $file);

        $phrases = [];
        foreach ($xml->xpath('//*[@translate|@translatable]') as $element) {
            $attributes = $element->attributes();
            if ((string) $attributes['translate'] === 'true' || (string) $attributes['translatable'] === 'true') {
                $phrases[] = (string) $element;
                continue;
            }
            foreach (preg_split('/[ ,]+/', (string) $attributes['translate'], -1, PREG_SPLIT_NO_EMPTY) as $name) {
                if (isset($attributes[$name])) {
                    $phrases[] = (string) $attributes[$name];
                } elseif (isset($element->$name)) {
                    $phrases[] = (string) $element->$name;
                }
            }
        }

        return $phrases;
    }
}
