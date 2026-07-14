<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_CRYPTO_DB
{
    private $plugin_schema_status;
    private $transactional_settlement_tables;

    public function invoices_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'jiuliu_crypto_invoices';
    }

    public function logs_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'jiuliu_crypto_logs';
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
            asset_amount decimal(36,18) NOT NULL DEFAULT 0,
            expected_raw varchar(80) NOT NULL,
            route_id varchar(64) NOT NULL,
            payment_method varchar(64) NOT NULL,
            adapter varchar(16) NOT NULL,
            chain_key varchar(64) NOT NULL,
            chain_id varchar(32) NOT NULL DEFAULT '',
            asset_symbol varchar(16) NOT NULL,
            asset_decimals tinyint(3) unsigned NOT NULL DEFAULT 6,
            fee_symbol varchar(16) NOT NULL DEFAULT 'TRX',
            required_confirmations smallint(5) unsigned NOT NULL DEFAULT 1,
            quote_scope char(64) NOT NULL DEFAULT '',
            receive_address varchar(128) NOT NULL,
            contract_address varchar(128) NOT NULL,
            network varchar(64) NOT NULL DEFAULT 'TRC20',
            status varchar(24) NOT NULL DEFAULT 'pending',
            active_key char(64) DEFAULT NULL,
            txid varchar(80) DEFAULT NULL,
            tx_key char(64) DEFAULT NULL,
            submitted_txid varchar(128) DEFAULT NULL,
            from_address varchar(128) DEFAULT NULL,
            actual_raw varchar(80) DEFAULT NULL,
            actual_amount decimal(36,18) DEFAULT NULL,
            block_timestamp bigint(20) unsigned DEFAULT NULL,
            public_token_hash char(64) NOT NULL,
            previous_public_token_hash char(64) DEFAULT NULL,
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
            UNIQUE KEY tx_key (tx_key),
            KEY txid (txid),
            KEY payment_id (payment_id),
            KEY user_id (user_id),
            KEY quote_active (quote_scope,active_key),
            KEY route_status (route_id,status),
            KEY status_expires (status,expires_at),
            KEY created_at (created_at)
        ) ENGINE=InnoDB {$charset_collate};";

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
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta($sql_invoices);
        dbDelta($sql_logs);

        // dbDelta() does not throw when table creation is rejected. Do not
        // activate financial processing until every required constraint exists.
        $this->plugin_schema_status = null;
        $this->transactional_settlement_tables = null;
        $schema_status = $this->plugin_schema_is_ready(true);
        if (is_wp_error($schema_status)) {
            return $schema_status;
        }

        return true;
    }

    /** Verify the freshly installed schema before exposing the gateway. */
    public function plugin_schema_is_ready($refresh = false)
    {
        global $wpdb;

        if (!$refresh && null !== $this->plugin_schema_status) {
            return $this->plugin_schema_status;
        }

        $required = array(
            $this->invoices_table() => array(
                'id', 'invoice_no', 'payment_id', 'zibll_order_num', 'user_id',
                'local_amount', 'local_currency', 'rate', 'rate_source', 'markup',
                'asset_amount', 'expected_raw', 'route_id', 'payment_method',
                'adapter', 'chain_key', 'chain_id', 'asset_symbol', 'asset_decimals',
                'fee_symbol', 'required_confirmations', 'quote_scope', 'receive_address', 'contract_address', 'network',
                'status', 'active_key', 'txid', 'tx_key', 'submitted_txid',
                'from_address', 'actual_raw', 'actual_amount', 'block_timestamp',
                'public_token_hash', 'previous_public_token_hash', 'created_at',
                'expires_at', 'paid_at', 'last_checked_at', 'updated_at',
                'error_code', 'note',
            ),
            $this->logs_table() => array(
                'id', 'invoice_id', 'user_id', 'level', 'event', 'message',
                'context', 'created_at',
            ),
        );

        foreach ($required as $table => $required_columns) {
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            if ('INNODB' !== strtoupper((string) $engine)) {
                return $this->cache_schema_error(
                    'plugin_schema_table_missing_or_not_innodb',
                    sprintf(__('数据表 %s 不存在或不是 InnoDB；插件初始化未完成。', 'jiuliu-crypto-payment'), $table)
                );
            }

            $columns = array_map('strtolower', (array) $wpdb->get_col($wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            )));
            $missing = array_values(array_diff($required_columns, $columns));
            if ($missing) {
                return $this->cache_schema_error(
                    'plugin_schema_columns_missing',
                    sprintf(
                        __('数据表 %1$s 缺少字段：%2$s；插件初始化未完成。', 'jiuliu-crypto-payment'),
                        $table,
                        implode(', ', $missing)
                    )
                );
            }
        }

        $index_rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            $this->invoices_table()
        ), ARRAY_A);
        $indexes = array();
        foreach ($index_rows as $row) {
            // mysqlnd commonly preserves the SELECT identifier case while
            // some drivers normalize associative keys. Accept both forms so
            // a healthy schema is not hidden merely because of the PHP driver.
            $name_value = isset($row['INDEX_NAME']) ? $row['INDEX_NAME'] : (isset($row['index_name']) ? $row['index_name'] : '');
            $sequence_value = isset($row['SEQ_IN_INDEX']) ? $row['SEQ_IN_INDEX'] : (isset($row['seq_in_index']) ? $row['seq_in_index'] : 0);
            $non_unique_value = isset($row['NON_UNIQUE']) ? $row['NON_UNIQUE'] : (isset($row['non_unique']) ? $row['non_unique'] : 1);
            $column_value = isset($row['COLUMN_NAME']) ? $row['COLUMN_NAME'] : (isset($row['column_name']) ? $row['column_name'] : '');
            $name = strtolower((string) $name_value);
            $sequence = absint($sequence_value);
            if (!$name || !$sequence) {
                continue;
            }
            if (!isset($indexes[$name])) {
                $indexes[$name] = array(
                    'non_unique' => absint($non_unique_value),
                    'columns'    => array(),
                );
            }
            $indexes[$name]['columns'][$sequence] = strtolower((string) $column_value);
        }

        foreach (array('invoice_no', 'zibll_order_num', 'active_key', 'tx_key') as $critical_column) {
            $found = false;
            foreach ($indexes as $index) {
                ksort($index['columns']);
                if (0 === (int) $index['non_unique'] && array($critical_column) === array_values($index['columns'])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return $this->cache_schema_error(
                    'plugin_schema_unique_index_missing',
                    sprintf(
                        __('链上支付单表缺少字段 %s 的单列唯一索引；为防止重复结算，网关已停止。', 'jiuliu-crypto-payment'),
                        $critical_column
                    )
                );
            }
        }

        $this->plugin_schema_status = true;
        return true;
    }

    private function cache_schema_error($code, $message)
    {
        $this->plugin_schema_status = new WP_Error($code, $message);
        return $this->plugin_schema_status;
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
                    ? __('链上支付单唯一标识发生冲突。', 'jiuliu-crypto-payment')
                    : __('链上支付单创建失败，请检查数据库表和站点日志。', 'jiuliu-crypto-payment')
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

    public function get_by_txid($txid, $chain_scope = '')
    {
        global $wpdb;
        if ($chain_scope) {
            $tx_key = hash('sha256', strtolower((string) $chain_scope) . '|' . JIULIU_CRYPTO_Util::normalize_txid($txid));
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->invoices_table()} WHERE tx_key = %s",
                $tx_key
            ));
        }
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

    /**
     * Serialize all settlement attempts for one Zibll parent payment. Quote
     * creation has a separate lock because opening a cashier must not release
     * or accidentally share ownership with a running financial settlement.
     */
    public function acquire_settlement_lock($payment_id, $timeout_seconds = 3)
    {
        global $wpdb;
        $name = $this->named_lock_prefix() . '_settlement_' . absint($payment_id);
        return '1' === (string) $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $name,
            max(0, min(10, absint($timeout_seconds)))
        ));
    }

    public function release_settlement_lock($payment_id)
    {
        global $wpdb;
        $name = $this->named_lock_prefix() . '_settlement_' . absint($payment_id);
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
    public function begin_zibll_settlement_guard($payment_id, $invoice_id, $txid)
    {
        global $wpdb;

        $payment_id = absint($payment_id);
        $invoice_id = absint($invoice_id);
        if (
            !$payment_id
            || !$invoice_id
            || empty($wpdb->zibpay_payment)
            || empty($wpdb->zibpay_order)
        ) {
            return new WP_Error('zibll_tables_missing', __('找不到子比订单数据表，无法安全结算。', 'jiuliu-crypto-payment'));
        }

        $transactional = $this->settlement_tables_are_transactional();
        if (is_wp_error($transactional)) {
            return $transactional;
        }

        if (false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error('settlement_transaction_failed', __('无法建立子比订单结算事务。', 'jiuliu-crypto-payment'));
        }

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT id,method,price,order_num,status,pay_num
             FROM {$wpdb->zibpay_payment}
             WHERE id = %d FOR UPDATE",
            $payment_id
        ), ARRAY_A);
        if (!$payment) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('zibll_payment_missing', __('对应的子比支付单不存在。', 'jiuliu-crypto-payment'));
        }

        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT id,status,pay_price
             FROM {$wpdb->zibpay_order}
             WHERE payment_id = %d ORDER BY id ASC FOR UPDATE",
            $payment_id
        ), ARRAY_A);
        if (!$children) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('zibll_children_missing', __('找不到子比关联子订单。', 'jiuliu-crypto-payment'));
        }

        // Lock our invoice after the Zibll rows. The close-order hook touches
        // Zibll first and our table second; keeping the same lock order avoids
        // a close/settle deadlock while making the final state check atomic.
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->invoices_table()}
             WHERE id = %d FOR UPDATE",
            $invoice_id
        ), ARRAY_A);
        if (!$invoice) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('invoice_not_found', __('链上支付单不存在，无法安全结算。', 'jiuliu-crypto-payment'));
        }

        return array(
            'payment' => $payment,
            'children'=> $children,
            'invoice' => $invoice,
            'txid'    => strtolower((string) $txid),
        );
    }

    /**
     * FOR UPDATE and rollback are only meaningful for transactional tables.
     * Fail closed rather than silently running an unsafe pseudo-transaction.
     */
    public function settlement_tables_are_transactional()
    {
        global $wpdb;

        if (null !== $this->transactional_settlement_tables) {
            return $this->transactional_settlement_tables;
        }

        $plugin_schema = $this->plugin_schema_is_ready();
        if (is_wp_error($plugin_schema)) {
            $this->transactional_settlement_tables = $plugin_schema;
            return $this->transactional_settlement_tables;
        }

        $order_meta = !empty($wpdb->zibpay_order_meta)
            ? $wpdb->zibpay_order_meta
            : $wpdb->prefix . 'zibpay_ordermeta';
        $tables = array(
            $this->invoices_table(),
            isset($wpdb->zibpay_payment) ? $wpdb->zibpay_payment : '',
            isset($wpdb->zibpay_order) ? $wpdb->zibpay_order : '',
            $order_meta,
        );

        foreach ($tables as $table) {
            if (!$table) {
                $this->transactional_settlement_tables = new WP_Error(
                    'zibll_tables_missing',
                    __('找不到完整的子比结算数据表，自动结算已停止。', 'jiuliu-crypto-payment')
                );
                return $this->transactional_settlement_tables;
            }

            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            if ('INNODB' !== strtoupper((string) $engine)) {
                $this->transactional_settlement_tables = new WP_Error(
                    'non_transactional_settlement_table',
                    sprintf(
                        __('数据表 %s 不是 InnoDB，无法保证关闭订单与到账结算的一致性；自动结算已停止。', 'jiuliu-crypto-payment'),
                        $table
                    )
                );
                return $this->transactional_settlement_tables;
            }
        }

        $this->transactional_settlement_tables = true;
        return true;
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
             SET zibll_order_num = %s,
                 previous_public_token_hash = public_token_hash,
                 public_token_hash = %s,
                 last_checked_at = NULL, updated_at = %s
             WHERE id = %d AND status = 'pending' AND expires_at >= %s",
            (string) $order_num,
            (string) $token_hash,
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            absint($id),
            JIULIU_CRYPTO_Util::utc_now_mysql()
        ));
    }

    public function rotate_invoice_public_token($id, $token_hash)
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET previous_public_token_hash = public_token_hash,
                 public_token_hash = %s, last_checked_at = NULL, updated_at = %s
             WHERE id = %d AND status = 'pending'",
            (string) $token_hash,
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            absint($id)
        ));
    }

    public function get_active_expected_raws($address, $route_id = '', $quote_scope = '')
    {
        global $wpdb;
        if ($quote_scope) {
            return array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT expected_raw FROM {$this->invoices_table()}
                 WHERE quote_scope = %s AND active_key IS NOT NULL",
                (string) $quote_scope
            )));
        }
        if ($route_id) {
            return array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT expected_raw FROM {$this->invoices_table()}
                 WHERE receive_address = %s AND route_id = %s AND active_key IS NOT NULL",
                (string) $address,
                (string) $route_id
            )));
        }
        return array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT expected_raw FROM {$this->invoices_table()}
             WHERE receive_address = %s AND active_key IS NOT NULL",
            (string) $address
        )));
    }

    public function update_invoice($id, $data)
    {
        global $wpdb;
        $data['updated_at'] = JIULIU_CRYPTO_Util::utc_now_mysql();
        $result = $wpdb->update($this->invoices_table(), $data, array('id' => absint($id)));
        return false !== $result;
    }

    public function mark_invoice_paid($id, $txid, $note)
    {
        global $wpdb;
        $now = JIULIU_CRYPTO_Util::utc_now_mysql();
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
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            absint($id),
            strtolower((string) $txid)
        ));
    }

    /**
     * Theme success hooks can send mail or fulfil stock outside MySQL. When an
     * exception or ambiguous COMMIT happens after entering those hooks, force a
     * visible review state and never allow an automatic replay.
     */
    public function mark_settlement_uncertain($id, $txid, $note)
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = 'review', error_code = 'zibll_settlement_uncertain',
                 note = %s, updated_at = %s
             WHERE id = %d AND status IN ('processing','paid') AND txid = %s",
            sanitize_text_field($note),
            JIULIU_CRYPTO_Util::utc_now_mysql(),
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
             WHERE id = %d AND status IN ('pending','expired','review','superseded','closed','closed_no_monitor')
               AND (error_code IS NULL OR error_code <> 'zibll_settlement_uncertain')",
            __('管理员已拒绝此支付单。', 'jiuliu-crypto-payment'),
            JIULIU_CRYPTO_Util::utc_now_mysql(),
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
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            absint($payment_id),
            (string) $except_order_num
        ));
    }

    /**
     * Atomically insert a replacement and retire the quote that currently owns
     * the same Zibll order number. A failed rate/insert/swap never destroys the
     * customer's previously usable quote. Callers hold the payment lock.
     */
    public function insert_replacing_order_quote($data, $old_id, $order_num, $payment_id)
    {
        global $wpdb;

        $old_id = absint($old_id);
        $suffix = '~q' . $old_id;
        $tombstone = substr((string) $order_num, 0, max(0, 80 - strlen($suffix))) . $suffix;
        $temporary = '~new~' . substr((string) (isset($data['invoice_no']) ? $data['invoice_no'] : wp_generate_uuid4()), 0, 74);
        $data['zibll_order_num'] = $temporary;

        if (false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error('invoice_replace_transaction_failed', __('无法开始链上报价替换事务。', 'jiuliu-crypto-payment'));
        }

        $old = $wpdb->get_row($wpdb->prepare(
            "SELECT id,payment_id,status,zibll_order_num FROM {$this->invoices_table()} WHERE id = %d FOR UPDATE",
            $old_id
        ));
        if (
            !$old
            || (int) $old->payment_id !== absint($payment_id)
            || (string) $old->zibll_order_num !== (string) $order_num
            || !in_array($old->status, array('pending', 'expired'), true)
        ) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('invoice_quote_changed', __('原链上报价状态已经变化，请重新打开收银台。', 'jiuliu-crypto-payment'));
        }

        if (false === $wpdb->insert($this->invoices_table(), $data)) {
            $is_duplicate = false !== stripos((string) $wpdb->last_error, 'duplicate');
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                $is_duplicate ? 'invoice_duplicate' : 'invoice_insert_failed',
                $is_duplicate ? __('链上支付单唯一标识发生冲突。', 'jiuliu-crypto-payment') : __('链上支付单创建失败，请检查数据库表和站点日志。', 'jiuliu-crypto-payment')
            );
        }
        $new_id = (int) $wpdb->insert_id;

        $retired = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET zibll_order_num = %s, status = 'superseded',
                 error_code = 'quote_replaced', updated_at = %s
             WHERE id = %d AND zibll_order_num = %s AND status IN ('pending','expired')",
            $tombstone,
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            $old_id,
            (string) $order_num
        ));
        $activated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()} SET zibll_order_num = %s WHERE id = %d AND zibll_order_num = %s",
            (string) $order_num,
            $new_id,
            $temporary
        ));

        if (1 !== $retired || 1 !== $activated || false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('invoice_quote_swap_failed', __('链上报价无法原子替换，请重新打开收银台。', 'jiuliu-crypto-payment'));
        }

        return $this->get_invoice($new_id);
    }

    public function claim_invoice($id, $txid, $transfer, $allowed_statuses, $preserve_error_code = false)
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

        $actual_raw = isset($transfer['value']) ? JIULIU_CRYPTO_Util::normalize_raw($transfer['value']) : null;
        $invoice = $this->get_invoice($id);
        $decimals = $invoice && isset($invoice->asset_decimals) ? max(0, min(18, absint($invoice->asset_decimals))) : 6;
        $actual_amount = null !== $actual_raw ? JIULIU_CRYPTO_Util::raw_to_decimal($actual_raw, $decimals) : null;
        $from = isset($transfer['from']) ? substr(sanitize_text_field($transfer['from']), 0, 128) : null;
        $block_timestamp = isset($transfer['block_timestamp']) ? (string) absint($transfer['block_timestamp']) : null;

        $error_assignment = $preserve_error_code ? 'error_code = error_code' : 'error_code = NULL';
        // The invoice snapshot held by a caller can become stale. Enforce the
        // high-risk replay barrier in the same atomic UPDATE that claims txid
        // ownership, so an ordinary worker can never clear an uncertainty
        // marker written by a competing settlement attempt.
        $uncertain_predicate = $preserve_error_code
            ? "error_code = 'zibll_settlement_uncertain'"
            : "(error_code IS NULL OR error_code <> 'zibll_settlement_uncertain')";
        $adapter = $invoice && isset($invoice->adapter) ? strtolower((string) $invoice->adapter) : 'tron';
        $chain_id = $invoice && isset($invoice->chain_id) ? strtolower((string) $invoice->chain_id) : '';
        $tx_key = hash('sha256', $adapter . '|' . $chain_id . '|' . JIULIU_CRYPTO_Util::normalize_txid($txid));
        $sql = $wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = 'processing', txid = %s, tx_key = %s, from_address = %s, actual_raw = %s,
                  actual_amount = %s, block_timestamp = %s, updated_at = %s, {$error_assignment}
             WHERE id = %d AND status IN (" . implode(',', $allowed) . ")
               AND (txid IS NULL OR txid = %s)
               AND {$uncertain_predicate}",
            strtolower($txid),
            $tx_key,
            $from,
            $actual_raw,
            $actual_amount,
            $block_timestamp,
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            absint($id),
            strtolower($txid)
        );

        $result = $wpdb->query($sql);
        return 1 === $result;
    }

    public function pending_for_scan($late_grace_hours = 24, $limit = 500, $include_closed = true)
    {
        global $wpdb;
        $cutoff = JIULIU_CRYPTO_Util::utc_mysql_from_timestamp(time() - absint($late_grace_hours) * HOUR_IN_SECONDS);
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
             WHERE status IN ({$history_statuses})
               AND (expires_at >= %s OR active_key IS NOT NULL)
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
        $cutoff = JIULIU_CRYPTO_Util::utc_mysql_from_timestamp(time() - max(1, absint($seconds)));
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET last_checked_at = %s, updated_at = %s
             WHERE id = %d
               AND (last_checked_at IS NULL OR last_checked_at < %s)",
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            JIULIU_CRYPTO_Util::utc_now_mysql(),
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
            JIULIU_CRYPTO_Util::utc_now_mysql(),
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
            ? __('子比订单已关闭；观察期内仍会检查到账，但只转人工处理，不会自动发货。', 'jiuliu-crypto-payment')
            : __('子比订单已关闭；已按设置停止自动链上监控，可由管理员使用交易哈希手动核验。', 'jiuliu-crypto-payment');
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
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            absint($payment_id)
        ));
    }

    /**
     * Apply the administrator's monitoring switch to already-closed invoices,
     * not only to orders closed after the setting changed.
     */
    public function sync_closed_monitoring($monitor)
    {
        global $wpdb;

        $from = $monitor ? 'closed_no_monitor' : 'closed';
        $to = $monitor ? 'closed' : 'closed_no_monitor';
        $note = $monitor
            ? __('管理员已开启关闭订单到账观察；观察期内发现到账只转人工，不会自动发货。', 'jiuliu-crypto-payment')
            : __('管理员已关闭关闭订单到账观察；此类支付单停止自动链上查询，可手工核验交易哈希。', 'jiuliu-crypto-payment');

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = %s, note = %s, updated_at = %s
             WHERE status = %s AND txid IS NULL",
            $to,
            $note,
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            $from
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
            JIULIU_CRYPTO_Util::utc_now_mysql(),
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
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            JIULIU_CRYPTO_Util::utc_now_mysql()
        ));
    }

    public function recover_stale_processing($minutes = 5)
    {
        global $wpdb;
        $cutoff = JIULIU_CRYPTO_Util::utc_mysql_from_timestamp(time() - max(1, absint($minutes)) * MINUTE_IN_SECONDS);
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET status = 'review', error_code = 'zibll_settlement_uncertain',
                 note = %s, updated_at = %s
             WHERE status = 'processing' AND updated_at < %s",
            __('结算进程中断，已转人工核对；请先检查子比权益是否已经发放。', 'jiuliu-crypto-payment'),
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            $cutoff
        ));
    }

    /**
     * Release exact-amount tails only for invoices whose complete chain window
     * was successfully covered in the current scan. This prevents a long RPC
     * outage from aging an unseen payment out of monitoring and immediately
     * reusing its amount tail.
     */
    public function release_scanned_active_keys($invoice_ids, $late_grace_hours = 24)
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $invoice_ids))));
        if (!$ids) {
            return 0;
        }

        $cutoff = JIULIU_CRYPTO_Util::utc_mysql_from_timestamp(time() - max(1, absint($late_grace_hours)) * HOUR_IN_SECONDS);
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET active_key = NULL, updated_at = %s
             WHERE active_key IS NOT NULL
               AND status NOT IN ('pending','processing')
               AND expires_at < %s
               AND id IN (" . implode(',', $ids) . ")",
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            $cutoff
        ));
    }

    /** Release tails for terminal states which are intentionally not scanned. */
    public function release_terminal_active_keys($late_grace_hours = 24)
    {
        global $wpdb;
        $cutoff = JIULIU_CRYPTO_Util::utc_mysql_from_timestamp(time() - max(1, absint($late_grace_hours)) * HOUR_IN_SECONDS);
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->invoices_table()}
             SET active_key = NULL, updated_at = %s
             WHERE active_key IS NOT NULL
               AND status IN ('paid','rejected','closed_no_monitor')
               AND expires_at < %s",
            JIULIU_CRYPTO_Util::utc_now_mysql(),
            $cutoff
        ));
    }

    public function delete_old_logs($days)
    {
        global $wpdb;
        $cutoff = JIULIU_CRYPTO_Util::utc_mysql_from_timestamp(time() - absint($days) * DAY_IN_SECONDS);
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
                'context'    => $safe_context ? JIULIU_CRYPTO_Util::json_encode($safe_context) : null,
                'created_at' => JIULIU_CRYPTO_Util::utc_now_mysql(),
            )
        );
    }

    private function sanitize_log_context($context)
    {
        if (!is_array($context)) {
            return array();
        }

        $blocked = array(
            'api_key', 'cron_token', 'token', 'public_token',
            'authorization', 'secret', 'password', 'x-cg-demo-api-key', 'tron-pro-api-key',
        );
        foreach ($context as $key => $value) {
            $normalized_key = strtolower((string) $key);
            $is_secret = false;
            foreach ($blocked as $blocked_key) {
                if (false !== strpos($normalized_key, $blocked_key)) {
                    $is_secret = true;
                    break;
                }
            }
            if ($is_secret) {
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
