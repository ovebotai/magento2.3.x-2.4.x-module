<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Controller\Adminhtml\Preview;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ovebot\Chat\Model\Security\PreviewToken;
use Ovebot\Chat\Model\StoreContext;
use Psr\Log\LoggerInterface;

/**
 * "Chat with the AI agent": opens the storefront with the chat open, through a new preview token.
 *
 * The token goes after "#", not in the query string: the browser does not send it to the server, so the page
 * still comes from the full page cache, and the token does not reach the logs or the Referer of other sites.
 */
class Open extends Action implements HttpGetActionInterface
{
    /**
     * Authorization level required to try the chat: the same as for the module page
     *
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Ovebot_Chat::main';

    public const FRAGMENT = 'ovebot_preview';

    /**
     * @var PreviewToken
     */
    private $previewToken;

    /**
     * @var StoreContext
     */
    private $storeContext;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Context $context
     * @param PreviewToken $previewToken
     * @param StoreContext $storeContext
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        PreviewToken $previewToken,
        StoreContext $storeContext,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->previewToken = $previewToken;
        $this->storeContext = $storeContext;
        $this->logger = $logger;
    }

    /**
     * Redirect to the storefront; without a token when none can be made, so the chat is shown closed
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();

        try {
            $url = $this->storeContext->get()->getBaseUrl();
        } catch (\Exception $e) {
            $this->logger->warning('Chat preview: storefront not found (' . get_class($e) . ').');

            return $redirect->setPath('ovebot_chat/dashboard/index');
        }

        try {
            $token = $this->previewToken->issue(time());
        } catch (\Exception $e) {
            $this->logger->warning('Chat preview: token not saved (' . get_class($e) . ').');
            $token = '';
        }

        return $redirect->setUrl($token !== '' ? $url . '#' . self::FRAGMENT . '=' . $token : $url);
    }
}
