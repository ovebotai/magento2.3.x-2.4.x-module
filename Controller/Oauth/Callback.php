<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Controller\Oauth;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ovebot\Chat\Model\IntegrationFactory;
use Ovebot\Chat\Model\OauthStateStorage;
use Ovebot\Chat\Model\Security\ReturnUrlValidator;
use Psr\Log\LoggerInterface;

/**
 * Return target of the OAuth authorization, served on the storefront: GET ovebot/oauth/callback?code=..&state=..
 *
 * The state is one the module issued itself and can be used once. The redirect goes only to the admin URL
 * the module stored when the authorization started, never to a URL from the query.
 */
class Callback extends Action implements HttpGetActionInterface
{
    /**
     * @var OauthStateStorage
     */
    private $states;

    /**
     * @var IntegrationFactory
     */
    private $integrationFactory;

    /**
     * @var ReturnUrlValidator
     */
    private $returnUrlValidator;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Context $context
     * @param OauthStateStorage $states
     * @param IntegrationFactory $integrationFactory
     * @param ReturnUrlValidator $returnUrlValidator
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        OauthStateStorage $states,
        IntegrationFactory $integrationFactory,
        ReturnUrlValidator $returnUrlValidator,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->states = $states;
        $this->integrationFactory = $integrationFactory;
        $this->returnUrlValidator = $returnUrlValidator;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Finish the authorization and go back to the admin page
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        $home = (string) $this->storeManager->getStore()->getBaseUrl();

        try {
            // one-time state: unknown or expired leads to the home page
            $pending = $this->states->pull($this->param('state'), time());
            if ($pending === null) {
                return $redirect->setUrl($home);
            }

            $integration = $this->integrationFactory->create();

            $error = $this->param('error');
            $code = $this->param('code');

            if ($error !== '' || $code === '') {
                $description = $this->param('error_description');
                if ($description === '') {
                    $description = $error !== '' ? $error : (string) __('Authorization was cancelled.');
                }
                $integration->flashOauthResult(['error' => $description]);
            } else {
                $integration->flashOauthResult($integration->handleCallback($code, $pending['verifier']));
            }
        } catch (\Exception $e) {
            $this->logger->warning('OAuth callback failed: ' . $e->getMessage());

            return $redirect->setUrl($home);
        }

        $returnUrl = $pending['return_url'];

        return $redirect->setUrl($this->returnUrlValidator->isAllowed($returnUrl) ? $returnUrl : $home);
    }

    /**
     * Read a query parameter as a string
     *
     * @param string $name
     * @return string
     */
    private function param(string $name): string
    {
        $value = $this->getRequest()->getParam($name);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
