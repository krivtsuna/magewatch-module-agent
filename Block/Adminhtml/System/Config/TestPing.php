<?php

declare(strict_types=1);

namespace MageWatch\Agent\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class TestPing extends Field
{
    /**
     * @var string
     */
    protected $_template = 'MageWatch_Agent::system/config/test_ping.phtml';

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getAjaxUrl(): string
    {
        return $this->getUrl('magewatch/config/testping');
    }

    public function getButtonHtml(): string
    {
        $button = $this->getLayout()->createBlock(\Magento\Backend\Block\Widget\Button::class)
            ->setData([
                'id' => 'magewatch-test-ping-button',
                'label' => __('Send Test Ping'),
                'type' => 'button',
            ]);

        return $button->toHtml();
    }
}
