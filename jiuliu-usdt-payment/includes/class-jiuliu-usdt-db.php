<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_USDT_DB
{
    public function invoices_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'jiuliu_usdt_invoices';
    }

    public function logs_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'jiuliu_usdt_logs';
    }

    public function install()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $invoices = $this->invoices_table();
        $logs = $this->logs_table();

        $sql_invoices = "CREATE TABLE {$invoices} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            invoice_no varchar(40) NOT NULL,
            payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            zibll_order_num varchar(80) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            local_amount decimal(20,8) NOT NULL DEFAULT 0,
            local_currency varchar(12) NOT NULL DEFAULT 'CNY',
            rate decimal(20,8) NOT NULL DEFAULT 0,
            rate_source varchar(32) NOT NULL DEFAULT 'fixed',
            markup decimal(10,4) NOT NULL DEFAULT 0,
            usdt_amount decimal(20,6) NOT NULL DEFAULT 0,
            expected_raw varchar(40) NOT NULL,
            receive_address varchar(64) NOT NULL,
            contract_address varchar(64) NOT NULL,
            network varchar(20) NOT NULL DEFAULT 'TRC20',
            status varchar(24) NOT NULL DEFAULT 'pending',
            active_key char(64) DEFAULT NULL,
            txid varchar(80) DEFAULT NULL,
            submitted_txid varchar(80) DEFAULT NULL,
            from_address varchar(64) DEFAULT NULL,
            actual_raw varchar(40) DEFAULT NULL,
            actual_amount decimal(20,6) DEFAULT NULL,
            block_timestamp bigint(20) unsigned DEFAULT NULL,
            public_token_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            paid_at datetime DEFAULT NULL,
            last_checked_at datetime DEFAULT NULL,
            updated_at datetime NOT NULL,
            error_code varchar(64) DEFAULT NULL,
            note text NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY invoice_no (invoice_no),
            UNIQUE KEY zibll_order_num (zibll_order_num),
            UNIQUE KEY active_key (active_key),
            UNIQUE KEY txid (txid),
            KEY payment_id (payment_id),
            KEY user_id (user_id),
            KEY status_expires (status,expires_at),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            level varchar(16) NOT NULL DEFAULT 'info',
            event varchar(64) NOT NULL,
            message text NOT NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY invoice_id (invoice_id),
            KEY event (event),
            KEY level_created (level,created_at),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql_invoices);
        dbDelta($sql_logs);

        update_option('jiuliu_usdt_db_version', JIULIU_USDT_DB_VERSION, false);
    }

    public function insert_invoice($data)
    {
        global $wpdb;
        $result = $wpdb->insert($this->invoices_table(), $data);
        if (false === $result) {
            $is_duplicate = false !== stripos((string) $wpdb->last_error, 'duplicate');
            return new WP_Error(
                $is_duplicate ? 'invoice_duplicate' : 'invoice_insert_failed',
                $is_duplicate
                    ? __('USDT 支付单唯一标识发生冲突。', 'jiuliu-usdt-payment')
                    : __('USDT 支付单创建失败，请检查数据库表和站点日志。', 'jiuliu-usdt-payment')
            );
        }

        return $this->get_invoice($wpdb->insert_id);
    }

    public function get_invoice($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->invoices_table()} WHERE id = %d",
            absint($id)
        ));
    }

    public function get_by_order_num($order_num)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->invoices_table()} WHERE zibll_order_num = %s",
            (string) $order_num
        ));
    }

    public function get_by_txid($txid)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->invoices_table()} WHERE txid = %s",
            strtolower((string) $txid)
        ));
    }

    public function get_reusable_by_payment_id($payment_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->invoices_table()}
             WHERE payment_id = %d AND status = 'pending'
             ORDER BY id DESC LIMIT 1",
            absint($payment_id)
        ));
    }

    /**
     * Serialize quote creation for one Zibll parent payment. MySQL named locks
     * are scoped to the current database connection and are released even if
     * PHP terminates unexpectedly.
     */
    public function acquire_payment_lock($payment_id, $timeout_seconds = 3)
    {
        global $wpdb;
        $name = $this->named_lock_prefix() . '_payment_' . absint($payment_id);
        return '1' === (string) $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $name,
            max(0, min(10, absint($timeout_seconds)))
        ));
    }

    public function release_payment_lock($payment_id)
    {
        global $wpdb;
        $name = $this->named_lock_prefix() . '_payment_' . absint($payment_id);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    private function named_lock_prefix()
    {
        // MySQL named locks are server-wide rather than database-scoped. Add a
        // table-derived suffix so two WordPress sites using the same database
        // server cannot unnecessarily block each other's payment IDs.
        return 'jiuliu_' . substr(md5($this->invoices_table()), 0, 12);
    }

    public function acquire_scan_lock()
    {
        global $wpdb;
        $name = $this->named_lock_prefix() . '_scan';
        return '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
    }

    public function release_scan_lock()
    {
        global $wpdb;
        $name = $this->named_lock_prefix() . '_scan';
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /**
     * Lock the Zibll parent and children on the same MySQL connection used by
     * ZibPay::payment_order(). This closes the otherwise unavoidable race in
     * which a user closes an order after our preflight read but immediately
     * before Zibll's settlement call (Zibll itself accepts status -1).
     */
    public function begin_zibll_settlement_guard($payment_id)
    {
        global $wpdb;

        $payment_id = absint($payment_id);
        if (
            !$payment_id
            || empty($wpdb->zibpay_payment)
            || empty($wpdb->zibpay_order)
        ) {
            return new WP_Error('zibll_tables_missing', __('找不到子比订单数据表，无法安全结算。', 'jiuliu-usdt-payment'));
        }

        if (false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error('settlement_transaction_failed', __('无法建立子比订单结算事务。', 'jiuliu-usdt-payment'));
        }

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT id,method,price,order_num,status,pay_num
             FROM {$wpdb->zibpay_payment}
             WHERE id = %d FOR UPDATE",
            $payment_id
        ), ARRAY_A);
        if (!$payment) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('zibll_payment_missing', __('对应的子比支付单不存在。', 'jiuliu-usdt-payment'));
        }

        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT id,status,pay_price
             FROM {$wpdb->zibpay_order}
             WHERE payment_id = %d ORDER BY id ASC FOR UPDATE",
            $payment_id
        ), ARRAY_A);
        if (!$children) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('zibll_children_missing', __('找不到子比关联子订单。', 'jiuliu-usdt-payment'));
        }

        return array(
            'payment' => $payment,
            'children'=> $children,
        );
    }

    public function commit_zibll_settlement_guard()
    {
        global $wpdb;
        return false !== $wpdb->query('COMMIT');
    }

    public function rollback_zibll_settlement_guard()
    {
        global $wpdb;
        return false !== $wpdb->query('ROLLBACK');
    }

    public function refresh_invoice_attempt($id, $order_num, $token_hash)
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET zibll_order_num = %s, public_token_hash = %s,
                 last_checked_at = NULL, updated_at = %s
             WHERE id = %d AND status = 'pending' AND expires_at >= %s",
            (string) $order_num,
            (string) $token_hash,
            JIULIU_USDT_Util::utc_now_mysql(),
            absint($id),
            JIULIU_USDT_Util::utc_now_mysql()
        ));
    }

    public function update_invoice($id, $data)
    {
        global $wpdb;
        $data['updated_at'] = JIULIU_USDT_Util::utc_now_mysql();
        $result = $wpdb->update($this->invoices_table(), $data, array('id' => absint($id)));
        return false !== $result;
    }

    public function mark_invoice_paid($id, $txid, $note)
    {
        global $wpdb;
        $now = JIULIU_USDT_Util::utc_now_mysql();
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = 'paid', paid_at = %s, error_code = NULL,
                 note = %s, updated_at = %s
             WHERE id = %d AND status = 'processing' AND txid = %s",
            $now,
            $note,
            $now,
            absint($id),
            strtolower((string) $txid)
        ));
        return 1 === $result;
    }

    public function mark_processing_review($id, $txid, $error_code, $note)
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = 'review', error_code = %s, note = %s, updated_at = %s
             WHERE id = %d AND status = 'processing' AND txid = %s",
            substr(sanitize_key($error_code), 0, 64),
            sanitize_text_field($note),
            JIULIU_USDT_Util::utc_now_mysql(),
            absint($id),
            strtolower((string) $txid)
        ));
    }

    public function reject_invoice($id)
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = 'rejected', error_code = 'admin_rejected',
                 note = %s, updated_at = %s
             WHERE id = %d AND status IN ('pending','expired','review','superseded','closed','closed_no_monitor')",
            __('管理员已拒绝此支付单。', 'jiuliu-usdt-payment'),
            JIULIU_USDT_Util::utc_now_mysql(),
            absint($id)
        ));
    }

    public function supersede_payment_invoices($payment_id, $except_order_num)
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = 'superseded', error_code = 'payment_reinitiated', updated_at = %s
             WHERE payment_id = %d AND zibll_order_num <> %s AND status IN ('pending','expired')",
            JIULIU_USDT_Util::utc_now_mysql(),
            absint($payment_id),
            (string) $except_order_num
        ));
    }

    public function claim_invoice($id, $txid, $transfer, $allowed_statuses)
    {
        global $wpdb;

        $allowed = array();
        foreach ((array) $allowed_statuses as $status) {
            if (in_array($status, array('pending', 'expired', 'superseded', 'review', 'closed', 'closed_no_monitor', 'processing'), true)) {
                $allowed[] = "'" . esc_sql($status) . "'";
            }
        }
        if (!$allowed) {
            return false;
        }

        $actual_raw = isset($transfer['value']) ? JIULIU_USDT_Util::normalize_raw($transfer['value']) : null;
        $actual_amount = null !== $actual_raw ? JIULIU_USDT_Util::raw_to_decimal($actual_raw, 6) : null;
        $from = isset($transfer['from']) ? substr(sanitize_text_field($transfer['from']), 0, 64) : null;
        $block_timestamp = isset($transfer['block_timestamp']) ? (string) absint($transfer['block_timestamp']) : null;

        $sql = $wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = 'processing', txid = %s, from_address = %s, actual_raw = %s,
                 actual_amount = %s, block_timestamp = %s, updated_at = %s, error_code = NULL
             WHERE id = %d AND status IN (" . implode(',', $allowed) . ")
               AND (txid IS NULL OR txid = %s)",
            strtolower($txid),
            $from,
            $actual_raw,
            $actual_amount,
            $block_timestamp,
            JIULIU_USDT_Util::utc_now_mysql(),
            absint($id),
            strtolower($txid)
        );

        $result = $wpdb->query($sql);
        return 1 === $result;
    }

    public function pending_for_scan($late_grace_hours = 24, $limit = 500, $include_closed = true)
    {
        global $wpdb;
        $cutoff = JIULIU_USDT_Util::utc_mysql_from_timestamp(time() - absint($late_grace_hours) * HOUR_IN_SECONDS);
        $limit = max(30, absint($limit));
        $latest_limit = (int) floor($limit * 0.5);
        $rotation_limit = (int) floor($limit * 0.3);
        $history_limit = max(1, $limit - $latest_limit - $rotation_limit);

        $latest = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->invoices_table()}
             WHERE status = 'pending'
             ORDER BY created_at DESC LIMIT %d",
            $latest_limit
        ));

        $latest_ids = array();
        foreach ((array) $latest as $invoice) {
            $latest_ids[] = absint($invoice->id);
        }
        $latest_exclusion = $latest_ids ? ' AND id NOT IN (' . implode(',', $latest_ids) . ')' : '';
        $rotation = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->invoices_table()}
             WHERE status = 'pending'{$latest_exclusion}
             ORDER BY COALESCE(last_checked_at,'1970-01-01 00:00:00') ASC, created_at DESC
             LIMIT %d",
            $rotation_limit
        ));

        $history_statuses = $include_closed
            ? "'expired','superseded','closed'"
            : "'expired','superseded'";
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->invoices_table()}
             WHERE status IN ({$history_statuses}) AND expires_at >= %s
             ORDER BY COALESCE(last_checked_at,'1970-01-01 00:00:00') ASC, created_at DESC
             LIMIT %d",
            $cutoff,
            $history_limit
        ));

        $merged = array();
        foreach (array_merge((array) $latest, (array) $rotation, (array) $history) as $invoice) {
            $merged[$invoice->id] = $invoice;
        }
        return array_values($merged);
    }

    public function acquire_check_slot($id, $seconds = 8)
    {
        global $wpdb;
        $cutoff = JIULIU_USDT_Util::utc_mysql_from_timestamp(time() - max(1, absint($seconds)));
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET last_checked_at = %s, updated_at = %s
             WHERE id = %d
               AND (last_checked_at IS NULL OR last_checked_at < %s)",
            JIULIU_USDT_Util::utc_now_mysql(),
            JIULIU_USDT_Util::utc_now_mysql(),
            absint($id),
            $cutoff
        ));
    }

    public function record_unbound_submission($id, $txid, $transfer, $note)
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET submitted_txid = %s, error_code = 'submitted_txid_mismatch',
                 note = %s, updated_at = %s
             WHERE id = %d
               AND status IN ('pending','expired','review','closed','closed_no_monitor','superseded')
               AND txid IS NULL",
            strtolower((string) $txid),
            sanitize_text_field($note),
            JIULIU_USDT_Util::utc_now_mysql(),
            absint($id)
        ));
    }

    public function close_payment_invoices($payment_id, $monitor, $type = '', $reason = '')
    {
        global $wpdb;
        $status = $monitor ? 'closed' : 'closed_no_monitor';
        $close_type = substr(sanitize_key($type), 0, 32);
        $error_code = $close_type ? 'zibll_closed_' . $close_type : 'zibll_closed';
        $note = $monitor
            ? __('子比订单已关闭；观察期内仍会检查到账，但只转人工处理，不会自动发货。', 'jiuliu-usdt-payment')
            : __('子比订单已关闭；已按设置停止自动链上监控，可由管理员使用交易哈希手动核验。', 'jiuliu-usdt-payment');
        if ($reason) {
            $note .= ' ' . sanitize_text_field($reason);
        }

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = %s, error_code = %s, note = %s, updated_at = %s
             WHERE payment_id = %d AND status IN ('pending','expired','superseded')",
            $status,
            substr($error_code, 0, 64),
            $note,
            JIULIU_USDT_Util::utc_now_mysql(),
            absint($payment_id)
        ));
    }

    public function payment_ids_due_for_zibll_close($limit = 500)
    {
        global $wpdb;
        return array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT payment_id FROM {$this->invoices_table()}
             WHERE payment_id > 0
               AND status IN ('pending','expired','superseded','review')
               AND expires_at <= %s
             ORDER BY payment_id ASC LIMIT %d",
            JIULIU_USDT_Util::utc_now_mysql(),
            max(1, absint($limit))
        )));
    }

    public function expire_due()
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = 'expired', error_code = 'expired', updated_at = %s
             WHERE status = 'pending' AND expires_at < %s",
            JIULIU_USDT_Util::utc_now_mysql(),
            JIULIU_USDT_Util::utc_now_mysql()
        ));
    }

    public function recover_stale_processing($minutes = 5)
    {
        global $wpdb;
        $cutoff = JIULIU_USDT_Util::utc_mysql_from_timestamp(time() - max(1, absint($minutes)) * MINUTE_IN_SECONDS);
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = 'review', error_code = 'processing_timeout',
                 note = %s, updated_at = %s
             WHERE status = 'processing' AND updated_at < %s",
            __('结算进程中断，已转人工核对；请先检查子比权益是否已经发放。', 'jiuliu-usdt-payment'),
            JIULIU_USDT_Util::utc_now_mysql(),
            $cutoff
        ));
    }

    public function release_old_active_keys($late_grace_hours = 24)
    {
        global $wpdb;
        $cutoff = JIULIU_USDT_Util::utc_mysql_from_timestamp(time() - max(1, absint($late_grace_hours)) * HOUR_IN_SECONDS);
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET active_key = NULL, updated_at = %s
             WHERE active_key IS NOT NULL AND status NOT IN ('pending','processing') AND expires_at < %s",
            JIULIU_USDT_Util::utc_now_mysql(),
            $cutoff
        ));
    }

    public function delete_old_logs($days)
    {
        global $wpdb;
        $cutoff = JIULIU_USDT_Util::utc_mysql_from_timestamp(time() - absint($days) * DAY_IN_SECONDS);
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->logs_table()} WHERE created_at < %s",
            $cutoff
        ));
    }

    public function log($event, $message, $invoice_id = 0, $level = 'info', $context = array(), $user_id = 0)
    {
        global $wpdb;

        $safe_context = $this->sanitize_log_context($context);
        return false !== $wpdb->insert(
            $this->logs_table(),
            array(
                'invoice_id' => absint($invoice_id),
                'user_id'    => absint($user_id),
                'level'      => substr(sanitize_key($level), 0, 16),
                'event'      => substr(sanitize_key($event), 0, 64),
                'message'    => sanitize_text_field($message),
                'context'    => $safe_context ? JIULIU_USDT_Util::json_encode($safe_context) : null,
                'created_at' => JIULIU_USDT_Util::utc_now_mysql(),
            )
        );
    }

    private function sanitize_log_context($context)
    {
        if (!is_array($context)) {
            return array();
        }

        $blocked = array('api_key', 'trongrid_api_key', 'cron_token', 'token', 'public_token');
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitize_log_context($value);
            }
        }

        return $context;
    }

    public function count_invoices($status = '', $search = '')
    {
        global $wpdb;
        $where = '1=1';
        $args = array();

        if ($status) {
            $where .= ' AND status = %s';
            $args[] = $status;
        }
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (invoice_no LIKE %s OR zibll_order_num LIKE %s OR txid LIKE %s OR submitted_txid LIKE %s)';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        $sql = "SELECT COUNT(*) FROM {$this->invoices_table()} WHERE {$where}";
        if ($args) {
            $sql = $wpdb->prepare($sql, $args);
        }
        return (int) $wpdb->get_var($sql);
    }

    public function list_invoices($status = '', $search = '', $page = 1, $per_page = 20)
    {
        global $wpdb;
        $where = '1=1';
        $args = array();

        if ($status) {
            $where .= ' AND status = %s';
            $args[] = $status;
        }
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (invoice_no LIKE %s OR zibll_order_num LIKE %s OR txid LIKE %s OR submitted_txid LIKE %s)';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        $offset = (max(1, absint($page)) - 1) * absint($per_page);
        $args[] = absint($per_page);
        $args[] = $offset;
        $sql = "SELECT * FROM {$this->invoices_table()} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        return $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    public function list_logs($page = 1, $per_page = 50)
    {
        global $wpdb;
        $offset = (max(1, absint($page)) - 1) * absint($per_page);
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->logs_table()} ORDER BY id DESC LIMIT %d OFFSET %d",
            absint($per_page),
            $offset
        ));
    }
}
