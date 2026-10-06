<?php
namespace Opencart\Catalog\Model\Extension\Whollycrypto\Payment;

class Whollycrypto extends \Opencart\System\Engine\Model
{
    public function getMethods(array $address = []): array
    {
        if (!$this->config->get('payment_whollycrypto_status') || $this->cart->hasSubscription()) {
            return [];
        }
        require_once DIR_EXTENSION . 'whollycrypto/system/library/whollycrypto/lib/autoload.php';
        try {
            (new \WhollyCrypto\OpenCart\Gateway($this->registry))->settings();
        } catch (\Throwable $e) {
            return [];
        }
        $this->load->language('extension/whollycrypto/payment/whollycrypto');
        return ['code' => 'whollycrypto', 'name' => $this->language->get('heading_title'),
            'option' => ['whollycrypto' => ['code' => 'whollycrypto.whollycrypto', 'name' => $this->language->get('heading_title')]],
            'sort_order' => (int) $this->config->get('payment_whollycrypto_sort_order')];
    }
}
