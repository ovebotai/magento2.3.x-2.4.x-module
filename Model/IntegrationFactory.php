<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\PackageInfo;
use Magento\Framework\ObjectManagerInterface;
use Ovebot\Chat\Model\Api\ClientFactory;

/**
 * Builds the integration of the shop, with its connection, its client and its URLs.
 *
 * Written by hand: a generated factory would build a new integration on every call, and the connection would be
 * probed more than once in a request.
 */
class IntegrationFactory
{
    private const MODULE_NAME = 'Ovebot_Chat';

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var ConnectionRepository
     */
    private $connections;

    /**
     * @var StoreContext
     */
    private $storeContext;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ClientFactory
     */
    private $clientFactory;

    /**
     * @var PackageInfo
     */
    private $packageInfo;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var Integration|null
     */
    private $integration;

    /**
     * @param ObjectManagerInterface $objectManager
     * @param ConnectionRepository $connections
     * @param StoreContext $storeContext
     * @param Config $config
     * @param ClientFactory $clientFactory
     * @param PackageInfo $packageInfo
     * @param ProductMetadataInterface $productMetadata
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        ConnectionRepository $connections,
        StoreContext $storeContext,
        Config $config,
        ClientFactory $clientFactory,
        PackageInfo $packageInfo,
        ProductMetadataInterface $productMetadata
    ) {
        $this->objectManager = $objectManager;
        $this->connections = $connections;
        $this->storeContext = $storeContext;
        $this->config = $config;
        $this->clientFactory = $clientFactory;
        $this->packageInfo = $packageInfo;
        $this->productMetadata = $productMetadata;
    }

    /**
     * Integration of the shop; the same instance during a request, so the connection is probed once
     *
     * @return Integration
     * @throws LocalizedException when the installation has no default store view
     */
    public function create(): Integration
    {
        if ($this->integration === null) {
            $connection = $this->connections->get();

            $client = $this->clientFactory->create([
                'accessToken' => $connection->getAccessToken(),
                'accountHost' => $this->config->getAccountHost(),
                'apiHost' => $this->config->getApiHost(),
                'userAgent' => $this->getUserAgent(),
            ]);

            $this->integration = $this->objectManager->create(Integration::class, [
                'connection' => $connection,
                'client' => $client,
                'storeView' => $this->storeContext->get(),
            ]);
        }

        return $this->integration;
    }

    /**
     * User agent sent to Ovebot.ai
     *
     * @return string
     */
    private function getUserAgent(): string
    {
        return sprintf(
            'OvebotAI-Magento/%s (Magento %s; PHP %s)',
            $this->packageInfo->getVersion(self::MODULE_NAME) ?: '0',
            $this->productMetadata->getVersion(),
            PHP_VERSION
        );
    }
}
