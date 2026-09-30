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
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ovebot\Chat\Model\IntegrationFactory;
use Psr\Log\LoggerInterface;

/**
 * Closes the connection of the shop. POST only: the form key is checked by the admin action.
 */
class Disconnect extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level required to disconnect
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
     * Disconnect and go back to the module page
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        try {
            $this->integrationFactory->create()->disconnect();
            $this->messageManager->addSuccessMessage(__('Disconnected from Ovebot.ai.'));
        } catch (\Exception $e) {
            $this->logger->warning('Disconnect failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('An error occurred. Please try again.'));
        }

        return $this->resultRedirectFactory->create()->setPath('ovebot_chat/dashboard/index');
    }
}
