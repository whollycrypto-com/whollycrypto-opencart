<?php
declare(strict_types=1);

namespace WhollyCrypto\OpenCart;

/** Uses OpenCart's registry, DB connection and native order history model. */
final class Gateway
{
    public function __construct(private object $registry) {}

    public function repository(): Repository
    {
        $db = $this->registry->get('db');
        return new Repository(static fn (string $sql) => $db->query($sql),
            static fn (string $sql) => $db->query($sql)->rows,
            static fn (string $value) => $db->escape($value), DB_PREFIX . 'whollycrypto_payment',
            array_map(static fn (string $name) => DB_PREFIX . $name,
                ['order', 'order_history', 'product', 'product_option_value', 'coupon_history', 'customer_reward']));
    }

    public function settings(): array
    {
        $s = [];
        foreach (['api_url', 'pay_url', 'project_id', 'store_id', 'api_key', 'ipn_secret'] as $key) {
            $s[$key] = $this->registry->get('config')->get('payment_whollycrypto_' . $key);
        }
        return Protocol::settings($s);
    }

    public function amount(array $order): string
    {
        $decimals = $this->registry->get('currency')->getDecimalPlace($order['currency_code']);
        return Protocol::fiat((float) $order['total'] * (float) $order['currency_value'], (int) $decimals);
    }

    public function checkout(int $orderId, array $options): string
    {
        $this->assertOrderStates();
        $repo = $this->repository();
        return $repo->locked($orderId, function () use ($repo, $orderId, $options) {
            $order = $this->order($orderId);
            $s = $this->settings();
            $a = $repo->load($orderId);
            if ($a && ($this->amount($order) !== $a['payload']['amount'] || $order['currency_code'] !== $a['payload']['currency'])) {
                throw new \RuntimeException('Order total changed. Reconcile the existing invoice first.');
            }
            if (!$a) {
                if ((int) $order['order_status_id'] !== 0 || !empty($order['subscription_id'])) {
                    throw new \RuntimeException('This order is not awaiting a new payment.');
                }
                $options['description'] = 'OpenCart order ' . $orderId;
                $options['metadata'] = ['integration' => 'opencart', 'shop_order_id' => (string) $orderId];
                if ($this->registry->get('config')->get('payment_whollycrypto_share_email')) {
                    $options['email'] = $order['email'];
                }
                $a = Protocol::attempt($s, $orderId, 'opencart:' . $order['store_id'] . ':' . $orderId,
                    $this->amount($order), $order['currency_code'], $options);
                $repo->transaction(function () use ($repo, $a, $orderId) {
                    $repo->save($a);
                    $this->history($orderId, $this->state('pending'), 'Awaiting Wholly Crypto payment.');
                });
            }
            $a = Protocol::fetch($s, $a);
            $repo->transaction(fn () => $this->apply($repo, $this->order($orderId), $a));
            return $a['checkout_url'];
        });
    }

    public function synchronize(int $orderId, ?string $raw = null, array $headers = []): array
    {
        $this->assertOrderStates();
        $repo = $this->repository();
        return $repo->locked($orderId, function () use ($repo, $orderId, $raw, $headers) {
            $order = $this->order($orderId);
            $a = $repo->load($orderId);
            if (!$a) {
                throw new \RuntimeException('No saved payment attempt.');
            }
            $s = $this->settings();
            $event = $raw !== null ? Protocol::notification($a, $raw, $headers, $s['ipn_secret']) : null;
            $a = Protocol::fetch($s, $a);
            if ($event && $a['sequence'] < $event['sequence']) {
                throw new \RuntimeException('Waiting for current API state.');
            }
            return $repo->transaction(fn () => $this->apply($repo, $order, $a));
        });
    }

    private function order(int $id): array
    {
        $this->registry->get('load')->model('checkout/order');
        $order = $this->registry->get('model_checkout_order')->getOrder($id);
        if (!$order || ($order['payment_method']['code'] ?? '') !== 'whollycrypto.whollycrypto'
            || (int) $order['store_id'] !== (int) $this->registry->get('config')->get('config_store_id')) {
            throw new \RuntimeException('Order does not belong to this payment method and shop.');
        }
        return $order;
    }

    private function state(string $name): int
    {
        $id = (int) $this->registry->get('config')->get('payment_whollycrypto_' . $name . '_status_id');
        if ($id < 1) {
            throw new \RuntimeException('Configure payment order statuses.');
        }
        return $id;
    }

    private function history(int $id, int $status, string $comment): void
    {
        $this->registry->get('model_checkout_order')->addHistory($id, $status, $comment, false, false);
    }

    private function assertOrderStates(): void
    {
        $config = $this->registry->get('config');
        $fulfilled = array_map('intval', array_merge((array) $config->get('config_processing_status'), (array) $config->get('config_complete_status')));
        $seen = [];
        foreach (['pending', 'paid', 'review', 'expired'] as $name) {
            $id = $this->state($name);
            if (in_array($id, $seen, true) || ($name === 'paid') !== in_array($id, $fulfilled, true)) {
                throw new \RuntimeException('Payment status configuration changed. Only verified payment may trigger fulfillment.');
            }
            $seen[] = $id;
        }
    }

    private function apply(Repository $repo, array $order, array $a): array
    {
        if ($this->amount($order) !== $a['payload']['amount'] || $order['currency_code'] !== $a['payload']['currency']) {
            $a['review'] = true;
        }
        $decision = Protocol::decision($a);
        if ($a['applied'] === 'paid') {
            if ($decision !== 'paid' && ($a['applied_note'] ?? '') !== 'review') {
                $this->history($a['order_id'], (int) $order['order_status_id'], 'Wholly Crypto: payment changed after settlement. Review the invoice; no automatic refund or order reversal was made.');
                $a['applied_note'] = 'review';
            }
        } elseif ($decision !== 'pending' && $decision !== $a['applied']) {
            if ($decision === 'paid' && (int) $order['order_status_id'] !== $this->state('pending')) {
                $decision = 'review';
            }
            $target = (int) $order['order_status_id'] === $this->state('pending') ? $this->state($decision) : (int) $order['order_status_id'];
            $this->history($a['order_id'], $target, 'Wholly Crypto invoice ' . $a['invoice_id'] . ': ' . $decision . '.');
            $a['applied'] = $decision;
        }
        $repo->save($a);
        return $a;
    }
}
