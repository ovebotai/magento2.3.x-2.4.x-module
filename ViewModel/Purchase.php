<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\Storefront\PurchaseProvider;
use Ovebot\Chat\Model\Widget\OptionsBuilder;
use Psr\Log\LoggerInterface;

/**
 * Purchases of the order success page, for widget.js. Same condition as the widget: no chat, no purchase event.
 */
class Purchase implements ArgumentInterface
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
     * @var PurchaseProvider
     */
    private $purchases;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ConnectionRepository $connections
     * @param OptionsBuilder $options
     * @param PurchaseProvider $purchases
     * @param LoggerInterface $logger
     */
    public function __construct(
        ConnectionRepository $connections,
        OptionsBuilder $options,
        PurchaseProvider $purchases,
        LoggerInterface $logger
    ) {
        $this->connections = $connections;
        $this->options = $options;
        $this->purchases = $purchases;
        $this->logger = $logger;
    }

    /**
     * Purchases to report, as JSON; empty when there is nothing to report or the widget is off
     *
     * @return string
     */
    public function getPurchasesJson(): string
    {
        try {
            if (!$this->options->isActive($this->connections->get())) {
                return '';
            }
            $purchases = $this->purchases->getPurchases();
        } catch (\Exception $e) {
            // the success page must never break because of the chat
            $this->logger->warning('Purchase event left out (' . get_class($e) . ').');

            return '';
        }

        if (!$purchases) {
            return '';
        }

        return (string) json_encode(
            $purchases,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
    }
}
