<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Controller\Cart;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\Storefront\CartProvider;
use Ovebot\Chat\Model\Widget\OptionsBuilder;
use Psr\Log\LoggerInterface;

/**
 * The cart of the visitor for cart.js: POST ovebot/cart/index, answers {"count": 3, "items": [...]}.
 *
 * Same gates as the widget plus the "Add to cart" switch: with the chat or the switch off the answer is 403.
 * The visitor reads a cart of their own and nothing changes, so no form key is asked; the answer is never
 * cached, the page that asks may itself come from the full page cache.
 */
class Index extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
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
     * @var CartProvider
     */
    private $cart;

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Context $context
     * @param ConnectionRepository $connections
     * @param OptionsBuilder $options
     * @param CartProvider $cart
     * @param JsonFactory $resultJsonFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        ConnectionRepository $connections,
        OptionsBuilder $options,
        CartProvider $cart,
        JsonFactory $resultJsonFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->connections = $connections;
        $this->options = $options;
        $this->cart = $cart;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->logger = $logger;
    }

    /**
     * The cart, or 403 while the chat or the switch is off
     *
     * @return Json
     */
    public function execute()
    {
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

        try {
            if (!$this->options->isCartEnabled($this->connections->get())) {
                return $result->setHttpResponseCode(403)->setData(['error' => 'Forbidden']);
            }

            return $result->setData($this->cart->get());
        } catch (\Exception $e) {
            // only the kind of error: the message may quote the data of the visitor
            $this->logger->warning('Cart not given to the chat (' . get_class($e) . ').');

            return $result->setHttpResponseCode(503)->setData(['error' => 'Unavailable']);
        }
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
