<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\KnowledgeBase;

use Ovebot\Chat\Model\Api\Exception\ApiException;

/**
 * The knowledge base quota of the plan stopped a CREATE. Carries the message of the API, shown once in the wizard.
 */
class QuotaReachedException extends ApiException
{
}
