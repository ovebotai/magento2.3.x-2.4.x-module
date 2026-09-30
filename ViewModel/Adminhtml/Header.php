<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\ViewModel\Adminhtml;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Module\PackageInfo;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\IntegrationFactory;

/**
 * Data of the page header: module version, connection badge, connect and disconnect links.
 */
class Header implements ArgumentInterface
{
    /**
     * @var IntegrationFactory
     */
    private $integrationFactory;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var PackageInfo
     */
    private $packageInfo;

    /**
     * @param IntegrationFactory $integrationFactory
     * @param UrlInterface $url
     * @param PackageInfo $packageInfo
     */
    public function __construct(IntegrationFactory $integrationFactory, UrlInterface $url, PackageInfo $packageInfo)
    {
        $this->integrationFactory = $integrationFactory;
        $this->url = $url;
        $this->packageInfo = $packageInfo;
    }

    /**
     * Module version
     *
     * @return string
     */
    public function getVersion(): string
    {
        return (string) $this->packageInfo->getVersion('Ovebot_Chat');
    }

    /**
     * Whether the shop is connected
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->integration()->isConnected();
    }

    /**
     * Whether Ovebot.ai could not be reached; the connection itself is kept
     *
     * @return bool
     */
    public function isUnreachable(): bool
    {
        return $this->integration()->probeConnection() === Integration::PROBE_UNREACHABLE;
    }

    /**
     * Text of the connection badge: "{workspace}:{agent|default}"
     *
     * @return string
     */
    public function getConnectionLabel(): string
    {
        return $this->integration()->getConnectionLabel();
    }

    /**
     * URL of the merchant's Ovebot.ai account
     *
     * @return string
     */
    public function getAccountUrl(): string
    {
        return $this->integration()->getAccountUrl();
    }

    /**
     * URL that starts the authorization ("I already have an account")
     *
     * @return string
     */
    public function getConnectUrl(): string
    {
        return $this->url->getUrl('ovebot_chat/connection/connect');
    }

    /**
     * URL the disconnect form posts to
     *
     * @return string
     */
    public function getDisconnectUrl(): string
    {
        return $this->url->getUrl('ovebot_chat/connection/disconnect');
    }

    /**
     * Question asked before disconnecting
     *
     * @return string
     */
    public function getDisconnectConfirmation(): string
    {
        return (string) __(
            'Disconnect this store from Ovebot.ai? The AI chat agent will stop working until you reconnect.'
        );
    }

    /**
     * URL of "Start Free"
     *
     * @return string
     */
    public function getRegisterUrl(): string
    {
        return $this->integration()->getRegisterUrl();
    }

    /**
     * Integration of the shop
     *
     * @return Integration
     */
    private function integration(): Integration
    {
        return $this->integrationFactory->create();
    }
}
