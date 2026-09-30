<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Controller\Adminhtml\Ajax;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ovebot\Chat\Model\Integration;
use Ovebot\Chat\Model\IntegrationFactory;
use Psr\Log\LoggerInterface;

/**
 * Base of the AJAX actions of the module page: answers in JSON and never lets an error out.
 *
 * POST only; the form key is checked by the admin action before execute() runs.
 */
abstract class AbstractAjax extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level required by every AJAX action
     *
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Ovebot_Chat::manage';

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

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
     * @param JsonFactory $resultJsonFactory
     * @param IntegrationFactory $integrationFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        IntegrationFactory $integrationFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->integrationFactory = $integrationFactory;
        $this->logger = $logger;
    }

    /**
     * Run the action
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            return $result->setData($this->handle($this->integrationFactory->create()));
        } catch (\Throwable $e) {
            $this->logger->warning(static::class . ': ' . $e->getMessage());

            return $result->setData([
                'success' => false,
                'error' => (string) __('An error occurred. Please try again.'),
            ]);
        }
    }

    /**
     * Do the work of the action
     *
     * @param Integration $integration
     * @return array the JSON answer
     */
    abstract protected function handle(Integration $integration): array;
}
