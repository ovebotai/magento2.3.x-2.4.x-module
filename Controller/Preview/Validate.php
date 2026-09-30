<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Controller\Preview;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ovebot\Chat\Model\Security\PreviewToken;

/**
 * Check of a preview token for widget.js: GET ovebot/preview/validate?token=..., answers {"valid": true|false}
 *
 * Never cached: the answer changes with the token of the shop, and the page that asks may itself come from the
 * full page cache.
 */
class Validate extends Action implements HttpGetActionInterface
{
    /**
     * @var PreviewToken
     */
    private $previewToken;

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @param Context $context
     * @param PreviewToken $previewToken
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(Context $context, PreviewToken $previewToken, JsonFactory $resultJsonFactory)
    {
        parent::__construct($context);
        $this->previewToken = $previewToken;
        $this->resultJsonFactory = $resultJsonFactory;
    }

    /**
     * Check the token
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $token = $this->getRequest()->getParam('token');
        $valid = is_string($token) && $this->previewToken->isValid($token, time());

        $result = $this->resultJsonFactory->create();
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
        foreach ($headers as $name => $value) {
            $result->setHeader($name, $value, true);
        }

        return $result->setData(['valid' => $valid]);
    }
}
