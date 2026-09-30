<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Block;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Ovebot\Chat\Model\Cache\WidgetCache;
use Ovebot\Chat\Model\Connection;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\StoreContext\StoreView;
use Ovebot\Chat\Model\Widget\OptionsBuilder;

/**
 * The chat widget, on every storefront page and every store view. The layout takes it out of the checkout, where
 * the payment data is typed (checkout_index_index.xml, multishipping_checkout.xml).
 *
 * The page keeps its full page cache: the block writes the same markup for every visitor, and gives the page the
 * tag of the widget (getIdentities), so a change of the widget removes the cached pages (Model\Cache\WidgetCache).
 * The tag is given also when the widget is off, so switching it on reaches the pages cached without it.
 */
class Widget extends Template implements IdentityInterface
{
    /**
     * @var ConnectionRepository
     */
    private $connections;

    /**
     * @var OptionsBuilder
     */
    private $options;

    /**
     * @var Connection|false|null the connection once read; false when it could not be read
     */
    private $connection;

    /**
     * @param Context $context
     * @param ConnectionRepository $connections
     * @param OptionsBuilder $options
     * @param array $data
     */
    public function __construct(
        Context $context,
        ConnectionRepository $connections,
        OptionsBuilder $options,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->connections = $connections;
        $this->options = $options;
    }

    /**
     * Cache tags of the pages the widget is on
     *
     * @return string[]
     */
    public function getIdentities()
    {
        return [WidgetCache::TAG];
    }

    /**
     * Whether the widget is shown
     *
     * @return bool
     */
    public function isActive(): bool
    {
        $connection = $this->connection();

        return $connection !== null && $this->options->isActive($connection);
    }

    /**
     * Configuration of widget.js, as JSON: the folder of the chat loader, the chat options, the preview check
     *
     * @return string
     */
    public function getConfigJson(): string
    {
        $connection = $this->connection();
        if ($connection === null) {
            return '{}';
        }

        return (string) json_encode(
            [
                'base' => $this->options->getLoaderBase($connection),
                'chat' => (object) $this->options->build($connection),
                // the store view of the page: the check is a request to the same site
                'previewUrl' => $this->getUrl(StoreView::ROUTE_PREVIEW_VALIDATE, ['_nosid' => true]),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * URL of widget.js; the minified file when the minification of JavaScript is on
     *
     * @return string
     */
    public function getScriptUrl(): string
    {
        return $this->getViewFileUrl('Ovebot_Chat::js/widget.js');
    }

    /**
     * Nothing at all when the widget is off
     *
     * @return string
     */
    protected function _toHtml()
    {
        return $this->isActive() ? parent::_toHtml() : '';
    }

    /**
     * The connection of the shop, read once; null when it cannot be read, which must never break the page
     *
     * @return Connection|null
     */
    private function connection(): ?Connection
    {
        if ($this->connection === null) {
            try {
                $this->connection = $this->connections->get();
            } catch (\Exception $e) {
                $this->_logger->warning('Ovebot chat widget left out (' . get_class($e) . ').');
                $this->connection = false;
            }
        }

        return $this->connection instanceof Connection ? $this->connection : null;
    }
}
