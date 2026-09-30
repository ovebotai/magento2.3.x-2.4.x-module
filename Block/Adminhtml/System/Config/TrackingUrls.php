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

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Stores > Configuration: tracking URL templates, as rows of carrier code and URL with {code}.
 *
 * The rows are stored as JSON by the backend model of the field and read by Model\Config.
 */
class TrackingUrls extends AbstractFieldArray
{
    /**
     * Define the columns of a row
     *
     * @return void
     */
    protected function _prepareToRender()
    {
        $this->addColumn('code', [
            'label' => __('Carrier code'),
            'class' => 'required-entry',
        ]);
        $this->addColumn('url', [
            'label' => __('Tracking URL'),
            'class' => 'required-entry',
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add carrier');
    }
}
