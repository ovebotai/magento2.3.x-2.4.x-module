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
 * Ovebot.ai could not be reached, or answered that it is temporarily unavailable. The call can be tried again;
 * the connection itself is still good.
 */
class ConnectionException extends OvebotException
{
}
