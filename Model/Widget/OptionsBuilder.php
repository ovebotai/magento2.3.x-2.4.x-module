<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Widget;

use Ovebot\Chat\Model\Connection;

/**
 * What the storefront widget is made of: whether it is shown, where the chat loader lives and the options it gets.
 *
 * The widget and the purchase event have the same condition: the chat is switched on, the setup wizard was
 * finished and the workspace is a valid slug (it becomes the host of a script).
 */
class OptionsBuilder
{
    /**
     * Whether the storefront shows the widget
     *
     * @param Connection $connection
     * @return bool
     */
    public function isActive(Connection $connection): bool
    {
        return $connection->isChatEnabled()
            && $connection->isSetupComplete()
            && $connection->getWorkspace() !== '';
    }

    /**
     * Folder of the chat loader and of the event script: https://{workspace}.ovebot.ai/widget/
     *
     * @param Connection $connection
     * @return string empty when there is no workspace
     */
    public function getLoaderBase(Connection $connection): string
    {
        $workspace = $connection->getWorkspace();

        return $workspace !== '' ? 'https://' . $workspace . '.ovebot.ai/widget/' : '';
    }

    /**
     * Options of the "chat" call of the chat loader
     *
     * Only what the settings form collects: an empty text or a value that is not a number is left out, so the
     * chat loader uses its own default. The default agent has no public id and is not named.
     *
     * @param Connection $connection
     * @return array
     */
    public function build(Connection $connection): array
    {
        $widget = $connection->getWidget();
        $options = [];

        foreach (Settings::STRING_KEYS as $key) {
            if (isset($widget[$key]) && is_scalar($widget[$key]) && (string) $widget[$key] !== '') {
                $options[$key] = (string) $widget[$key];
            }
        }
        foreach (Settings::INT_KEYS as $key) {
            if (isset($widget[$key]) && is_scalar($widget[$key]) && is_numeric($widget[$key])) {
                $options[$key] = (int) $widget[$key];
            }
        }

        $agent = $connection->getAgent();
        if ($agent !== '' && $agent !== 'default') {
            $options['agent'] = $agent;
        }

        return $options;
    }

    /**
     * What the storefront shows for this connection, as a string that changes when the widget changes
     *
     * An inactive widget is always the empty string, so a change made while the chat is off does not count.
     *
     * @param Connection $connection
     * @return string
     */
    public function getState(Connection $connection): string
    {
        if (!$this->isActive($connection)) {
            return '';
        }

        return (string) json_encode([$this->getLoaderBase($connection), $this->build($connection)]);
    }
}
