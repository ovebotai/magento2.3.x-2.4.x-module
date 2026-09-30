<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Controller\Adminhtml\Connection;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ovebot\Chat\Model\IntegrationFactory;
use Psr\Log\LoggerInterface;

/**
 * Starts the OAuth authorization and sends the merchant to Ovebot.ai.
 */
class Connect extends Action implements HttpGetActionInterface
{
    /**
     * Authorization level required to connect
     *
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Ovebot_Chat::manage';

    /**
     * @var IntegrationFactory
     */
    private $integrationFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Context $context
     * @param IntegrationFactory $integrationFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        IntegrationFactory $integrationFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->integrationFactory = $integrationFactory;
        $this->logger = $logger;
    }

    /**
     * Redirect to the authorization page of Ovebot.ai
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        $returnUrl = $this->getUrl('ovebot_chat/dashboard/index');

        try {
            $integration = $this->integrationFactory->create();
            $integration->ensureCredentials();
            $user = $this->_auth->getUser();

            return $redirect->setUrl(
                $integration->beginAuthorization($returnUrl, $user ? (int) $user->getId() : 0)
            );
        } catch (\Exception $e) {
            $this->logger->warning('Authorization could not start: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('The connection to Ovebot.ai could not be started. Please try again.')
            );

            return $redirect->setUrl($returnUrl);
        }
    }
}
