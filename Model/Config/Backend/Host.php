<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Ovebot\Chat\Model\Api\Client;

/**
 * A host override of the developer options: a bare host name, or nothing.
 *
 * The client ignores a value that is not a host name and uses the default host; refusing it at save tells the
 * merchant about it.
 */
class Host extends Value
{
    /**
     * Trim the value and refuse what is not a host name
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        $host = is_scalar($value) ? trim((string) $value) : '';

        if ($host !== '' && !preg_match(Client::HOST_PATTERN, $host)) {
            throw new LocalizedException(__(
                'The host "%1" is not valid. Enter a host name without protocol and without path,'
                . ' for example api.ovebot.ai.',
                $host
            ));
        }

        $this->setValue($host);

        return parent::beforeSave();
    }
}
