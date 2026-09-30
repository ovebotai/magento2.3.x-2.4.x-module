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
 * Files of the module that must agree with each other and that no PHP class reads: the list of what Magento may
 * drop against the schema, and the layouts that keep the chat widget out of the checkout.
 */
class ModuleFilesTest extends TestCase
{
    private const WIDGET_BLOCK = 'ovebot.chat.widget';

    /**
     * @var string
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testTheWhitelistListsExactlyTheSchema()
    {
        $schema = [];
        foreach ($this->xml('etc/db_schema.xml')->table as $table) {
            $entry = ['column' => [], 'index' => [], 'constraint' => []];
            foreach ($table->column as $column) {
                $entry['column'][(string) $column['name']] = true;
            }
            foreach ($table->index as $index) {
                $entry['index'][(string) $index['referenceId']] = true;
            }
            foreach ($table->constraint as $constraint) {
                $entry['constraint'][(string) $constraint['referenceId']] = true;
            }
            $schema[(string) $table['name']] = array_filter($entry);
        }

        $whitelist = json_decode((string) file_get_contents($this->root . '/etc/db_schema_whitelist.json'), true);

        $this->assertIsArray($whitelist);
        $this->assertSame($this->sorted($schema), $this->sorted($whitelist));
    }

    /**
     * @dataProvider checkoutHandles
     */
    public function testTheWidgetIsLeftOutOfTheCheckout(string $handle)
    {
        $removed = $this->xml('view/frontend/layout/' . $handle . '.xml')
            ->xpath('//referenceBlock[@name="' . self::WIDGET_BLOCK . '"][@remove="true"]');

        $this->assertCount(1, $removed, $handle . ' must remove the chat widget');
    }

    public static function checkoutHandles(): array
    {
        return [
            'one-page checkout' => ['checkout_index_index'],
            'checkout with several addresses' => ['multishipping_checkout'],
        ];
    }

    /**
     * @dataProvider successHandles
     */
    public function testTheSuccessPagesKeepTheWidgetAndReportThePurchase(string $handle)
    {
        $layout = $this->xml('view/frontend/layout/' . $handle . '.xml');

        // widget.js sends the purchase, so the page that reports it must not lose the widget
        $this->assertCount(0, $layout->xpath('//referenceBlock[@name="' . self::WIDGET_BLOCK . '"]'));
        $this->assertCount(1, $layout->xpath('//update[@handle="ovebot_chat_purchase"]'));
    }

    public static function successHandles(): array
    {
        return [
            'one-page checkout' => ['checkout_onepage_success'],
            'checkout with several addresses' => ['multishipping_checkout_success'],
        ];
    }

    public function testTheWidgetIsDeclaredOnEveryPage()
    {
        $blocks = $this->xml('view/frontend/layout/default.xml')
            ->xpath('//block[@name="' . self::WIDGET_BLOCK . '"]');

        $this->assertCount(1, $blocks);
    }

    /**
     * Read an XML file of the module
     *
     * @param string $path relative to the module
     * @return \SimpleXMLElement
     */
    private function xml(string $path): \SimpleXMLElement
    {
        $xml = simplexml_load_string((string) file_get_contents($this->root . '/' . $path));
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml, $path);

        return $xml;
    }

    /**
     * Sort the tables, their parts and the names in them, so two lists compare whatever their order
     *
     * @param array $tables
     * @return array
     */
    private function sorted(array $tables): array
    {
        ksort($tables);
        foreach ($tables as &$parts) {
            ksort($parts);
            foreach ($parts as &$names) {
                ksort($names);
            }
        }

        return $tables;
    }
}
