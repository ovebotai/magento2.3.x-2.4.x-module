<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Ovebot\Chat\Model\ConnectionRepository;

/**
 * Stores > Configuration: the state of the connection and a link to the module page, where the connection and
 * the everyday settings are.
 *
 * Reads the stored connection only; Ovebot.ai is not called from the configuration page.
 */
class ModuleLink extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Ovebot_Chat::system/config/module_link.phtml';

    /**
     * @var ConnectionRepository
     */
    private $connections;

    /**
     * @param Context $context
     * @param ConnectionRepository $connections
     * @param array $data
     */
    public function __construct(Context $context, ConnectionRepository $connections, array $data = [])
    {
        parent::__construct($context, $data);
        $this->connections = $connections;
    }

    /**
     * Render the row without the scope label and without "Use Default": there is no value behind it
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    /**
     * Whether the shop is connected to Ovebot.ai
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connections->get()->isConnected();
    }

    /**
     * Workspace and agent of the connection: "{workspace}:{agent|default}"; empty when there is no workspace
     *
     * @return string
     */
    public function getConnectionLabel(): string
    {
        $connection = $this->connections->get();
        $workspace = $connection->getWorkspace();
        $agent = $connection->getAgent();

        return $workspace !== '' ? $workspace . ':' . ($agent !== '' ? $agent : 'default') : '';
    }

    /**
     * URL of the module page
     *
     * @return string
     */
    public function getModuleUrl(): string
    {
        return $this->getUrl('ovebot_chat/dashboard/index');
    }

    /**
     * The content of the row comes from the template
     *
     * @param AbstractElement $element
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }
}
