<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_CRYPTO_Util
{
    const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function random_token($bytes = 24)
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (Exception $e) {
            return wp_generate_password($bytes * 2, false, false);
        }
    }

    public static function is_valid_tron_address($address)
    {
        $address = trim((string) $address);
        if (34 !== strlen($address) || 'T' !== substr($address, 0, 1)) {
            return false;
        }

        $decoded = self::base58_decode($address);
        if (false === $decoded || 25 !== strlen($decoded)) {
            return false;
        }

        $payload  = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21, 4);
        if ("\x41" !== substr($payload, 0, 1)) {
            return false;
        }

        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        return hash_equals($expected, $checksum);
    }

    public static function base58_decode($input)
    {
        if (!is_string($input) || '' === $input) {
            return false;
        }

        $bytes = array(0);
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $value = strpos(self::BASE58_ALPHABET, $input[$i]);
            if (false === $value) {
                return false;
            }

            $carry = $value;
            for ($j = 0, $count = count($bytes); $j < $count; $j++) {
                $carry += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xff;
                $carry >>= 8;
            }

            while ($carry > 0) {
                $bytes[] = $carry & 0xff;
                $carry >>= 8;
            }
        }

        $leading = 0;
        while ($leading < $length && '1' === $input[$leading]) {
            $leading++;
        }

        $output = str_repeat("\x00", $leading);
        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            if ($i === count($bytes) - 1 && 0 === $bytes[$i] && $leading > 0) {
                continue;
            }
            $output .= chr($bytes[$i]);
        }

        return $output;
    }

    public static function decimal_to_raw($amount, $decimals = 6)
    {
        $formatted = number_format((float) $amount, $decimals, '.', '');
        return ltrim(str_replace('.', '', $formatted), '0') ?: '0';
    }

    public static function raw_to_decimal($raw, $decimals = 6)
    {
        $raw = preg_replace('/\D/', '', (string) $raw);
        $raw = ltrim($raw, '0');
        if ('' === $raw) {
            $raw = '0';
        }

        if (0 === $decimals) {
            return $raw;
        }

        $raw = str_pad($raw, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($raw, 0, -$decimals);
        $fraction = substr($raw, -$decimals);
        return $whole . '.' . $fraction;
    }

    public static function normalize_raw($raw)
    {
        $raw = preg_replace('/\D/', '', (string) $raw);
        $raw = ltrim($raw, '0');
        return '' === $raw ? '0' : $raw;
    }

    public static function is_valid_txid($txid)
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/D', self::normalize_txid($txid));
    }

    public static function normalize_txid($txid)
    {
        $txid = strtolower(trim((string) $txid));
        if (0 === strpos($txid, '0x')) {
            $txid = substr($txid, 2);
        }
        return $txid;
    }

    public static function client_ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public static function local_mysql_from_timestamp($timestamp)
    {
        return date('Y-m-d H:i:s', (int) $timestamp);
    }

    public static function utc_now_mysql()
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function utc_mysql_from_timestamp($timestamp)
    {
        return gmdate('Y-m-d H:i:s', (int) $timestamp);
    }

    public static function utc_timestamp_from_mysql($mysql)
    {
        $value = trim((string) $mysql);
        return $value ? strtotime($value . ' UTC') : 0;
    }

    public static function display_datetime($utc_mysql)
    {
        if (!$utc_mysql) {
            return '—';
        }

        return get_date_from_gmt($utc_mysql, 'Y-m-d H:i:s');
    }

    public static function timestamp_from_mysql($mysql)
    {
        return strtotime((string) $mysql);
    }

    public static function mask_address($address)
    {
        $address = (string) $address;
        if (strlen($address) < 16) {
            return $address;
        }
        return substr($address, 0, 8) . '…' . substr($address, -8);
    }

    public static function json_encode($value)
    {
        return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
