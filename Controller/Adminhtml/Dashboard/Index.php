<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Ovebot\Chat\Block\Adminhtml\Page;
use Ovebot\Chat\Model\IntegrationFactory;

/**
 * The module page: setup wizard, dashboard and settings.
 */
class Index extends Action implements HttpGetActionInterface
{
    /**
     * Authorization level required to open the page
     *
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Ovebot_Chat::main';

    /**
     * Authorization level required by the settings view
     */
    public const MANAGE_RESOURCE = 'Ovebot_Chat::manage';

    /**
     * @var PageFactory
     */
    private $resultPageFactory;

    /**
     * @var IntegrationFactory
     */
    private $integrationFactory;

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param IntegrationFactory $integrationFactory
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        IntegrationFactory $integrationFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->integrationFactory = $integrationFactory;
    }

    /**
     * Render the module page
     *
     * @return \Magento\Backend\Model\View\Result\Page
     */
    public function execute()
    {
        $integration = $this->integrationFactory->create();
        $integration->ensureCredentials();
        // a revoked token is dropped here, so THIS page load already shows the first step
        $integration->probeConnection();
        $integration->syncSettings();
        $integration->selfHealEndpoints();

        $oauthError = $integration->pullOauthError();
        // the view comes from the state; the request can only ask for the settings of a finished setup
        $view = Page::VIEW_SETUP;
        if ($integration->isSetupComplete()) {
            $view = $this->wantsSettings() ? Page::VIEW_SETTINGS : Page::VIEW_DASHBOARD;
        }

        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Ovebot_Chat::main');
        $page->getConfig()->getTitle()->prepend(__('Ovebot AI'));

        $block = $page->getLayout()->getBlock('ovebot.chat.page');
        if ($block) {
            $block->setData('view', $view);
            $block->setData('oauth_error', $oauthError);
        }

        return $page;
    }

    /**
     * Whether the settings were asked for, by somebody allowed to change them
     *
     * The settings show the keys of the feed and of the order endpoint, so the right to see the page is not
     * enough for them.
     *
     * @return bool
     */
    private function wantsSettings(): bool
    {
        if ($this->getRequest()->getParam('view') !== Page::VIEW_SETTINGS) {
            return false;
        }

        if (!$this->_authorization->isAllowed(self::MANAGE_RESOURCE)) {
            $this->messageManager->addNoticeMessage(
                __('You need the "Manage connection and settings" permission to open the Ovebot AI settings.')
            );

            return false;
        }

        return true;
    }
}
