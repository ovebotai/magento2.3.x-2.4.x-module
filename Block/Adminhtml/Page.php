<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Block\Adminhtml;

use Magento\Backend\Block\Template;

/**
 * Container of the module page. The controller tells it which view to show; the data comes from view models.
 *
 * @api
 */
class Page extends Template
{
    public const VIEW_SETUP = 'setup';
    public const VIEW_DASHBOARD = 'dashboard';
    public const VIEW_SETTINGS = 'settings';

    /**
     * View to show
     *
     * @return string
     */
    public function getView(): string
    {
        $view = (string) $this->getData('view');

        return $view !== '' ? $view : self::VIEW_SETUP;
    }

    /**
     * OAuth error to show once; empty when there is none
     *
     * @return string
     */
    public function getOauthError(): string
    {
        return (string) $this->getData('oauth_error');
    }
}
