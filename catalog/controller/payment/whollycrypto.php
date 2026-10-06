<?php
namespace Opencart\Catalog\Controller\Extension\Whollycrypto\Payment;

require_once DIR_EXTENSION . 'whollycrypto/system/library/whollycrypto/lib/autoload.php';

use WhollyCrypto\OpenCart\Gateway;
use WhollyCrypto\OpenCart\Protocol;

class Whollycrypto extends \Opencart\System\Engine\Controller
{
    public function index(): string
    {
        $this->load->language('extension/whollycrypto/payment/whollycrypto');
        $this->session->data['wholly_token'] = $this->session->data['wholly_token'] ?? bin2hex(random_bytes(24));
        return $this->load->view('extension/whollycrypto/payment/whollycrypto', [
            'confirm_url' => $this->url->link('extension/whollycrypto/payment/whollycrypto.confirm', 'language=' . $this->config->get('config_language'), true),
            'wholly_token' => $this->session->data['wholly_token'],
            'text_description' => $this->language->get('text_description'),
            'button_pay' => $this->language->get('button_pay'),
            'text_retry' => $this->language->get('text_retry'),
        ]);
    }

    public function confirm(): void
    {
        $json = [];
        try {
            if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST'
                || !$this->config->get('payment_whollycrypto_status')
                || !isset($this->session->data['wholly_token'])
                || !hash_equals($this->session->data['wholly_token'], (string) ($this->request->post['wholly_token'] ?? ''))
                || ($this->session->data['payment_method']['code'] ?? '') !== 'whollycrypto.whollycrypto'
                || empty($this->session->data['order_id']) || $this->cart->hasSubscription()) {
                throw new \RuntimeException('Invalid checkout session.');
            }
            $orderId = (int) $this->session->data['order_id'];
            $return = html_entity_decode($this->url->link('extension/whollycrypto/payment/whollycrypto.returning', 'language=' . $this->config->get('config_language'), true), ENT_QUOTES, 'UTF-8');
            $options = ['ipn_url' => html_entity_decode($this->url->link('extension/whollycrypto/payment/whollycrypto.ipn', 'order=' . $orderId, true), ENT_QUOTES, 'UTF-8'),
                'redirect_url' => $return, 'cancel_url' => $return];
            $json['redirect'] = (new Gateway($this->registry))->checkout($orderId, $options);
        } catch (\Throwable $e) {
            $json['error'] = Protocol::error($e);
            $this->log->write($json['error']);
        }
        $this->json($json);
    }

    public function ipn(): void
    {
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->addHeader('Allow: POST');
            $this->json(['error' => 'method_not_allowed'], 405);
            return;
        }
        $raw = file_get_contents('php://input', false, null, 0, 262145);
        if ($raw === false || strlen($raw) > 262144) {
            $this->json(['error' => 'body_too_large'], 413);
            return;
        }
        try {
            (new Gateway($this->registry))->synchronize((int) ($this->request->get['order'] ?? 0), $raw, [
                'Wholly-Signature' => $this->request->server['HTTP_WHOLLY_SIGNATURE'] ?? '',
                'Wholly-Event-Id' => $this->request->server['HTTP_WHOLLY_EVENT_ID'] ?? '',
                'Wholly-Delivery-Id' => $this->request->server['HTTP_WHOLLY_DELIVERY_ID'] ?? '',
            ]);
            $this->json(['ok' => true]);
        } catch (\WhollyCrypto\OpenCart\Sdk\Exception\InvalidSignatureException $e) {
            $this->json(['error' => 'invalid_signature'], 401);
        } catch (\Throwable $e) {
            $this->response->addHeader('Retry-After: 30');
            $this->json(['error' => 'retry_later'], 503);
        }
    }

    public function returning(): void
    {
        // Browser returns never change payment state or fetch an untrusted invoice ID.
        $this->load->language('extension/whollycrypto/payment/whollycrypto');
        $data = ['heading_title' => $this->language->get('heading_title'), 'text_message' => $this->language->get('text_return'),
            'button_continue' => $this->language->get('button_continue'), 'continue' => $this->url->link('common/home'),
            'breadcrumbs' => [], 'header' => $this->load->controller('common/header'),
            'footer' => $this->load->controller('common/footer'),
            'column_left' => $this->load->controller('common/column_left'), 'column_right' => $this->load->controller('common/column_right'),
            'content_top' => $this->load->controller('common/content_top'), 'content_bottom' => $this->load->controller('common/content_bottom')];
        // The order was already placed. Clear only the session-bound cart after returning.
        $returnOrder = (int) ($this->session->data['order_id'] ?? $this->session->data['wholly_last_order'] ?? 0);
        if ($returnOrder > 0) {
            $a = (new Gateway($this->registry))->repository()->load($returnOrder);
            if ($a && $a['invoice_id']) {
                $data['text_message'] .= '<p><a class="btn btn-primary" href="' . htmlspecialchars($a['checkout_url'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($this->language->get('text_view_payment'), ENT_QUOTES, 'UTF-8') . '</a></p>';
                if (!empty($this->session->data['order_id'])) {
                    $this->cart->clear();
                    $this->session->data['wholly_last_order'] = $returnOrder;
                    foreach (['order_id', 'payment_method', 'payment_methods', 'shipping_method', 'shipping_methods', 'coupon', 'reward', 'wholly_token'] as $key) {
                        unset($this->session->data[$key]);
                    }
                }
            }
        }
        $this->response->setOutput($this->load->view('common/success', $data));
    }

    private function json(array $body, int $status = 200): void
    {
        $this->response->addHeader('HTTP/1.1 ' . $status);
        $this->response->addHeader('Content-Type: application/json');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->setOutput(json_encode($body, JSON_THROW_ON_ERROR));
    }
}
