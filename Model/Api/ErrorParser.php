<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Api;

/**
 * Reads the error bodies of Ovebot.ai.
 *
 * Shapes: {error: {code, message, fields}}, {error: "..", error_description}, {message}, {errors: {field: [..]}}.
 */
class ErrorParser
{
    private const QUOTA_STATUSES = [402, 409, 429];
    private const QUOTA_MESSAGE_PATTERN = '/limit reached|quota|exceed|too many/i';

    /**
     * One readable message; "HTTP <status>" when the body holds none
     *
     * @param Response $response
     * @return string
     */
    public function message(Response $response): string
    {
        $body = $response->getBody();
        $parts = [];

        if (isset($body['error']) && is_array($body['error'])) {
            if (isset($body['error']['message']) && is_scalar($body['error']['message'])) {
                $parts[] = (string) $body['error']['message'];
            }
            if (isset($body['error']['fields']) && is_array($body['error']['fields'])) {
                $parts = array_merge($parts, $this->fieldMessages($body['error']['fields']));
            }
        } elseif (isset($body['error']) && is_scalar($body['error'])) {
            $parts[] = isset($body['error_description']) && is_scalar($body['error_description'])
                ? (string) $body['error_description']
                : (string) $body['error'];
        }

        if (!$parts && isset($body['message']) && is_scalar($body['message'])) {
            $parts[] = (string) $body['message'];
        }

        if (isset($body['errors']) && is_array($body['errors'])) {
            $parts = array_merge($parts, $this->fieldMessages($body['errors']));
        }

        $text = trim(implode(' ', array_unique($parts)));

        return $text !== '' ? $text : 'HTTP ' . $response->getStatus();
    }

    /**
     * The error code, when the body holds one
     *
     * @param Response $response
     * @return string
     */
    public function code(Response $response): string
    {
        $body = $response->getBody();

        return isset($body['error']['code']) && is_scalar($body['error']['code'])
            ? (string) $body['error']['code']
            : '';
    }

    /**
     * Whether the error says a plan limit was reached
     *
     * @param Response $response
     * @return bool
     */
    public function isQuota(Response $response): bool
    {
        if (in_array($response->getStatus(), self::QUOTA_STATUSES, true)) {
            return true;
        }

        $code = strtolower($this->code($response));
        if ($code !== '' && (strpos($code, 'limit') !== false || strpos($code, 'quota') !== false)) {
            return true;
        }

        return (bool) preg_match(self::QUOTA_MESSAGE_PATTERN, $this->message($response));
    }

    /**
     * Flatten {field: [message, ...]} or {field: message}
     *
     * @param array $fields
     * @return string[]
     */
    private function fieldMessages(array $fields): array
    {
        $messages = [];
        foreach ($fields as $fieldMessages) {
            foreach ((array) $fieldMessages as $message) {
                if (is_scalar($message)) {
                    $messages[] = (string) $message;
                }
            }
        }

        return $messages;
    }
}
