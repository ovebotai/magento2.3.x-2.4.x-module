<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Api\Exception;

/**
 * The token endpoint refused the request: wrong or used code, revoked refresh token. The remedy is to connect
 * the account again, not to try the call again.
 */
class AuthException extends OvebotException
{
}
