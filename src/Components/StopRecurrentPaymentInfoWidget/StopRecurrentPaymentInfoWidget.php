<?php

namespace Crm\AppleAppstoreModule\Components;

use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\ApplicationModule\Widget\BaseLazyWidget;

class StopRecurrentPaymentInfoWidget extends BaseLazyWidget
{
    private $templateName = 'stop_recurrent_payment_info_widget.latte';

    public function identifier()
    {
        return 'stopapplerecurrentpaymentbuttonwidget';
    }

    public function render($recurrentPayment)
    {
        if ($recurrentPayment->payment_gateway->code !== AppleAppstoreGateway::GATEWAY_CODE) {
            return;
        }
        $this->template->setFile(__DIR__ . DIRECTORY_SEPARATOR . $this->templateName);
        $this->template->render();
    }
}
