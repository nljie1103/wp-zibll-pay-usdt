<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_CRYPTO_Ajax
{
    private $settings;
    private $db;
    private $invoices;

    public function __construct(JIULIU_CRYPTO_Settings $settings, JIULIU_CRYPTO_DB $db, JIULIU_CRYPTO_Invoices $invoices)
    {
        $this->settings = $settings;
        $this->db = $db;
        $this->invoices = $invoices;

        add_action('wp_ajax_jiuliu_crypto_submit_txid', array($this, 'submit_txid'));
        add_action('wp_ajax_nopriv_jiuliu_crypto_submit_txid', array($this, 'submit_txid'));
    }

    public function submit_txid()
    {
        $invoice_id = isset($_POST['invoice_id']) ? absint($_POST['invoice_id']) : 0;
        $security = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';
        $public_token = isset($_POST['public_token']) ? sanitize_text_field(wp_unslash($_POST['public_token'])) : '';
        $txid = isset($_POST['txid']) ? strtolower(sanitize_text_field(wp_unslash($_POST['txid']))) : '';

        if (!$invoice_id || !wp_verify_nonce($security, 'jiuliu_crypto_invoice_' . $invoice_id)) {
            wp_send_json_error(array('message' => __('请求验证失败，请刷新支付页面后重试。', 'jiuliu-crypto-payment')), 403);
        }

        if (!$this->settings->get('frontend_manual_txid', 1)) {
            wp_send_json_error(array('message' => __('前台交易哈希核验已关闭，请联系管理员。', 'jiuliu-crypto-payment')), 403);
        }

        if ($this->settings->get('pause_monitoring', 0)) {
            wp_send_json_error(array('message' => __('链上核验处于紧急暂停状态，请联系管理员。', 'jiuliu-crypto-payment')), 503);
        }

        if (!$this->allow_attempt($invoice_id)) {
            wp_send_json_error(array('message' => __('提交过于频繁，请五分钟后再试。', 'jiuliu-crypto-payment')), 429);
        }

        $result = $this->invoices->verify_public_txid($invoice_id, $txid, $public_token);
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), 400);
        }

        if ('paid' === $result->status) {
            wp_send_json_success(array(
                'status'  => 'paid',
                'message' => __('链上付款已确认，子比订单和权益已自动完成。', 'jiuliu-crypto-payment'),
            ));
        }

        if ('review' === $result->status) {
            wp_send_json_success(array(
                'status'  => 'review',
                'message' => __('已确认收到该交易，但金额或时间异常，管理员将人工处理。', 'jiuliu-crypto-payment'),
            ));
        }

        wp_send_json_success(array(
            'status'  => $result->status,
            'message' => __('交易已提交，正在等待链上确认。', 'jiuliu-crypto-payment'),
        ));
    }

    private function allow_attempt($invoice_id)
    {
        $key = 'jiuliu_crypto_tx_attempt_' . md5($invoice_id . '|' . JIULIU_CRYPTO_Util::client_ip());
        $count = (int) get_transient($key);
        if ($count >= 5) {
            return false;
        }
        set_transient($key, $count + 1, 5 * MINUTE_IN_SECONDS);
        return true;
    }
}
