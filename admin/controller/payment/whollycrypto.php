<?php
namespace Opencart\Admin\Controller\Extension\Whollycrypto\Payment;

require_once DIR_EXTENSION . 'whollycrypto/system/library/whollycrypto/lib/autoload.php';

use WhollyCrypto\OpenCart\Gateway;
use WhollyCrypto\OpenCart\Protocol;

class Whollycrypto extends \Opencart\System\Engine\Controller
{
    private const ROUTE = 'extension/whollycrypto/payment/whollycrypto';
    private const FIELDS = ['api_url', 'pay_url', 'project_id', 'store_id', 'api_key', 'ipn_secret'];

    public function index(): void
    {
        $this->load->language(self::ROUTE);
        $this->document->setTitle('Wholly Crypto');
        $data = [];
        foreach (self::FIELDS as $key) {
            $data[$key] = in_array($key, ['api_key', 'ipn_secret'], true) ? '' : $this->config->get('payment_whollycrypto_' . $key);
        }
        foreach (['pending_status_id', 'paid_status_id', 'review_status_id', 'expired_status_id', 'status', 'share_email', 'sort_order'] as $key) {
            $data[$key] = $this->config->get('payment_whollycrypto_' . $key);
        }
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['save'] = $this->url->link(self::ROUTE . '.save', 'user_token=' . $this->session->data['user_token']);
        $data['test'] = $this->url->link(self::ROUTE . '.test', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    public function save(): void
    {
        try {
            $this->permission();
            $s = [];
            foreach (self::FIELDS as $key) {
                $value = trim((string) ($this->request->post['payment_whollycrypto_' . $key] ?? ''));
                if (in_array($key, ['api_key', 'ipn_secret'], true) && $value === '') {
                    $value = (string) $this->config->get('payment_whollycrypto_' . $key);
                }
                $s[$key] = $value;
            }
            $s = Protocol::settings($s);
            $this->load->model('localisation/order_status');
            $validIds = array_map('intval', array_column($this->model_localisation_order_status->getOrderStatuses(), 'order_status_id'));
            $fulfillment = array_map('intval', array_merge((array) $this->config->get('config_processing_status'), (array) $this->config->get('config_complete_status')));
            $states = [];
            foreach (['pending', 'paid', 'review', 'expired'] as $key) {
                $id = (int) ($this->request->post['payment_whollycrypto_' . $key . '_status_id'] ?? 0);
                if (!in_array($id, $validIds, true) || ($key === 'paid') !== in_array($id, $fulfillment, true) || in_array($id, $states, true)) {
                    throw new \InvalidArgumentException('Choose four different statuses. Only the paid status may be an OpenCart processing/complete status.');
                }
                $states[$key . '_status_id'] = $id;
            }
            $values = [];
            foreach (array_merge($s, $states) as $key => $value) {
                $values['payment_whollycrypto_' . $key] = $value;
            }
            foreach (['status', 'share_email'] as $key) {
                $values['payment_whollycrypto_' . $key] = ($this->request->post['payment_whollycrypto_' . $key] ?? '') === '1' ? 1 : 0;
            }
            $values['payment_whollycrypto_sort_order'] = max(0, min(999, (int) ($this->request->post['payment_whollycrypto_sort_order'] ?? 0)));
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_whollycrypto', $values);
            $this->json(['success' => 'Connection saved. Test it and complete the staging checklist before enabling live payments.']);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => ['warning' => $e->getMessage()]]);
        } catch (\Throwable $e) {
            $this->json(['error' => ['warning' => Protocol::error($e)]]);
        }
    }

    public function test(): void
    {
        try {
            $this->permission();
            $s = (new Gateway($this->registry))->settings();
            Protocol::client($s)->listStorePaymentAssets($s['project_id'], $s['store_id']);
            $this->json(['success' => 'API connection and store access work. This does not test invoice creation or callback delivery.']);
        } catch (\Throwable $e) {
            $this->json(['error' => ['warning' => Protocol::error($e)]]);
        }
    }

    public function install(): void
    {
        if (!$this->user->hasPermission('modify', 'marketplace/extension')) {
            throw new \RuntimeException('Permission denied.');
        }
        if (PHP_VERSION_ID < 80100 || !extension_loaded('curl')) {
            throw new \RuntimeException('PHP 8.1+ with cURL is required.');
        }
        (new Gateway($this->registry))->repository()->install();
        $this->load->model('setting/setting');
        if ($this->model_setting_setting->getSetting('payment_whollycrypto')) {
            return; // Reinstall retains connection and order-state mapping.
        }
        $this->load->model('localisation/language');
        $this->load->model('localisation/order_status');
        $settings = ['payment_whollycrypto_status' => 0, 'payment_whollycrypto_share_email' => 0,
            'payment_whollycrypto_sort_order' => 0,
            'payment_whollycrypto_paid_status_id' => (int) (((array) $this->config->get('config_processing_status'))[0] ?? 2)];
        foreach (['pending' => 'Wholly: awaiting payment', 'review' => 'Wholly: review payment', 'expired' => 'Wholly: expired unpaid'] as $key => $label) {
            $names = [];
            foreach ($this->model_localisation_language->getLanguages() as $language) {
                $names[(int) $language['language_id']] = ['name' => $label];
            }
            $settings['payment_whollycrypto_' . $key . '_status_id'] = $this->model_localisation_order_status->addOrderStatus(['order_status' => $names]);
        }
        $this->model_setting_setting->editSetting('payment_whollycrypto', $settings);
    }

    public function uninstall(): void
    {
        // Never drop payment records or order statuses. Reconcile open orders first.
    }

    private function permission(): void
    {
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST'
            || !$this->user->hasPermission('modify', self::ROUTE)
            || !isset($this->session->data['user_token'])
            || !hash_equals($this->session->data['user_token'], (string) ($this->request->get['user_token'] ?? ''))) {
            throw new \RuntimeException('Permission denied.');
        }
    }

    private function json(array $body): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->setOutput(json_encode($body, JSON_THROW_ON_ERROR));
    }
}
