<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Controller\Orders;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Ovebot\Chat\Model\ConnectionRepository;
use Ovebot\Chat\Model\Order\OrderIdentifier;
use Ovebot\Chat\Model\Order\OrderLookup;
use Ovebot\Chat\Model\Order\PhoneNormalizer;
use Ovebot\Chat\Model\Security\BasicAuth;
use Ovebot\Chat\Model\Security\RateLimiter;
use Ovebot\Chat\Model\Util\ErrorSummary;
use Psr\Log\LoggerInterface;

/**
 * Order lookup for the AI agent ("where is my order?"): POST ovebot/orders/index
 *
 * HTTP Basic with the user and password of the shop. Body in JSON (form fields are accepted too):
 * {"id": "000000123", "email": ".."} or {"id": "#123", "phone": ".."}, exactly one of email and phone.
 * Answers {"success": true, "data": {..}} or {"success": false, "error": ".."}.
 *
 * Checks, in this order: blocked client with wrong credentials (429), chat or order tracking switched off (403,
 * before the credentials, so Ovebot.ai reads "switched off" and not "wrong credentials"), credentials (401, counted
 * by the rate limit), request (400).
 *
 * A block never stops the right credentials. Behind a proxy every caller may come with the address of the proxy,
 * and a block that also stopped Ovebot.ai would let anyone switch the order tracking off with ten wrong requests.
 * The password is random, so the limit is there against noise, not against guessing.
 */
class Index extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    // \z, not $: in PHP "$" also matches before a trailing line break
    private const EMAIL_PATTERN = '/^[^@\s]+@[^@\s]+\.[^@\s]+\z/u';

    private const EMAIL_MAX_LENGTH = 254;

    /**
     * @var ConnectionRepository
     */
    private $connections;

    /**
     * @var RateLimiter
     */
    private $rateLimiter;

    /**
     * @var BasicAuth
     */
    private $basicAuth;

    /**
     * @var OrderIdentifier
     */
    private $orderIdentifier;

    /**
     * @var PhoneNormalizer
     */
    private $phoneNormalizer;

    /**
     * @var OrderLookup
     */
    private $orderLookup;

    /**
     * @var ErrorSummary
     */
    private $errorSummary;

    /**
     * @var RemoteAddress
     */
    private $remoteAddress;

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
     * @param RateLimiter $rateLimiter
     * @param BasicAuth $basicAuth
     * @param OrderIdentifier $orderIdentifier
     * @param PhoneNormalizer $phoneNormalizer
     * @param OrderLookup $orderLookup
     * @param ErrorSummary $errorSummary
     * @param RemoteAddress $remoteAddress
     * @param JsonFactory $resultJsonFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        ConnectionRepository $connections,
        RateLimiter $rateLimiter,
        BasicAuth $basicAuth,
        OrderIdentifier $orderIdentifier,
        PhoneNormalizer $phoneNormalizer,
        OrderLookup $orderLookup,
        ErrorSummary $errorSummary,
        RemoteAddress $remoteAddress,
        JsonFactory $resultJsonFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->connections = $connections;
        $this->rateLimiter = $rateLimiter;
        $this->basicAuth = $basicAuth;
        $this->orderIdentifier = $orderIdentifier;
        $this->phoneNormalizer = $phoneNormalizer;
        $this->orderLookup = $orderLookup;
        $this->errorSummary = $errorSummary;
        $this->remoteAddress = $remoteAddress;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->logger = $logger;
    }

    /**
     * A call between servers, authenticated with HTTP Basic: there is no form key to check
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * A call between servers, authenticated with HTTP Basic: there is no form key to check
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Look up the order
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $now = time();
        $ip = (string) $this->remoteAddress->getRemoteAddress();

        $connection = $this->connections->get();
        $request = $this->getRequest();
        $given = $request instanceof HttpRequest ? $this->basicAuth->credentials($request) : null;
        $authenticated = $this->basicAuth->matches($given, $connection->getOrderUser(), $connection->getOrderPass());

        // only the wrong credentials of a blocked client are turned away: see the note of the class
        if (!$authenticated && $this->rateLimiter->isBlocked($ip, $now)) {
            return $this->answer(
                429,
                ['success' => false, 'error' => 'Too many failed attempts.'],
                ['Retry-After' => (string) RateLimiter::BLOCK]
            );
        }

        if (!$connection->isChatEnabled() || !$connection->isOrderEnabled()) {
            return $this->answer(403, ['success' => false, 'error' => 'Forbidden']);
        }

        if (!$authenticated) {
            $this->rateLimiter->recordFailure($ip, $now);

            return $this->answer(
                401,
                ['success' => false, 'error' => 'Unauthorized'],
                ['WWW-Authenticate' => 'Basic realm="Ovebot.ai"']
            );
        }
        $this->rateLimiter->reset($ip);

        $input = $this->input();
        $identifier = $this->orderIdentifier->parse(isset($input['id']) ? $input['id'] : '');
        $email = isset($input['email']) && is_scalar($input['email']) ? trim((string) $input['email']) : '';
        $phone = isset($input['phone']) && is_scalar($input['phone']) ? trim((string) $input['phone']) : '';

        // exactly one of email and phone: never both, never neither
        if ($identifier === null || (($email === '') === ($phone === ''))) {
            return $this->answer(400, ['success' => false, 'error' => 'Invalid request.']);
        }
        if ($email !== '' && !$this->isEmail($email)) {
            return $this->answer(400, ['success' => false, 'error' => 'Invalid email.']);
        }
        $phoneCore = $phone !== '' ? $this->phoneNormalizer->core($phone) : '';
        if ($phone !== '' && $phoneCore === '') {
            return $this->answer(400, ['success' => false, 'error' => 'Invalid phone.']);
        }

        $type = $email !== '' ? OrderLookup::TYPE_EMAIL : OrderLookup::TYPE_PHONE;
        $value = $email !== '' ? $email : $phoneCore;
        try {
            // described in the store view the order was placed on, whatever store view the URL points to
            $data = $this->orderLookup->find($identifier, $type, $value, $now);
        } catch (\Exception $e) {
            // where it failed, not the message: the message of a database error may hold the email of the request
            $this->logger->warning('Order lookup failed: ' . $this->errorSummary->describe($e));

            return $this->answer(500, ['success' => false, 'error' => 'Order lookup failed.']);
        }

        return $this->answer(
            200,
            $data !== null ? ['success' => true, 'data' => $data] : ['success' => false, 'error' => 'Order not found.']
        );
    }

    /**
     * Values of the request: the JSON body, or the form fields when the body is not JSON
     *
     * @return array
     */
    private function input(): array
    {
        $request = $this->getRequest();
        $raw = $request instanceof HttpRequest ? (string) $request->getContent() : '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'id' => $request->getParam('id'),
            'email' => $request->getParam('email'),
            'phone' => $request->getParam('phone'),
        ];
    }

    /**
     * Whether the text looks like an email address; the lookup compares it exactly
     *
     * @param string $email
     * @return bool
     */
    private function isEmail(string $email): bool
    {
        return strlen($email) <= self::EMAIL_MAX_LENGTH && preg_match(self::EMAIL_PATTERN, $email) === 1;
    }

    /**
     * JSON answer
     *
     * @param int $status
     * @param array $data
     * @param string[] $headers added to the ones of every answer
     * @return \Magento\Framework\Controller\Result\Json
     */
    private function answer(int $status, array $data, array $headers = [])
    {
        $result = $this->resultJsonFactory->create();
        $result->setHttpResponseCode($status);
        $headers += [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
        foreach ($headers as $name => $value) {
            $result->setHeader($name, $value, true);
        }

        return $result->setData($data);
    }
}
