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

    /**
     * Canonicalize a plain decimal without scientific notation.
     *
     * Financial callers should pass strings. Floats are accepted only at an
     * external-data boundary and are first frozen to the requested precision;
     * every base-unit operation after that point is string-only.
     *
     * @return string|false
     */
    public static function normalize_decimal($value, $max_decimals = 18, $allow_negative = false)
    {
        $max_decimals = max(0, min(36, (int) $max_decimals));
        if (is_float($value)) {
            if (!is_finite($value)) {
                return false;
            }
            $value = number_format($value, $max_decimals, '.', '');
        } elseif (is_int($value)) {
            $value = (string) $value;
        } elseif (!is_string($value)) {
            return false;
        }

        $value = trim($value);
        if (!preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/D', $value, $matches)) {
            return false;
        }
        $negative = '-' === $matches[1];
        if ($negative && !$allow_negative) {
            return false;
        }

        $whole = ltrim($matches[2], '0');
        $whole = '' === $whole ? '0' : $whole;
        $fraction = isset($matches[3]) ? $matches[3] : '';
        if (strlen($fraction) > $max_decimals) {
            $discarded = substr($fraction, $max_decimals);
            if ('' !== trim($discarded, '0')) {
                return false;
            }
            $fraction = substr($fraction, 0, $max_decimals);
        }
        $fraction = rtrim($fraction, '0');
        $is_zero = '0' === $whole && '' === $fraction;

        return ($negative && !$is_zero ? '-' : '') . $whole . ('' !== $fraction ? '.' . $fraction : '');
    }

    /**
     * Convert a decimal to base units without float or native-integer math.
     * Non-zero precision beyond the requested scale is rejected.
     *
     * @return string|false
     */
    public static function decimal_to_raw($amount, $decimals = 6)
    {
        $decimals = max(0, min(36, (int) $decimals));
        $parts = self::decimal_parts($amount, false);
        if (false === $parts || $parts['negative']) {
            return false;
        }

        if ($parts['scale'] > $decimals) {
            $discarded = substr($parts['fraction'], $decimals);
            if ('' !== trim($discarded, '0')) {
                return false;
            }
            $parts['fraction'] = substr($parts['fraction'], 0, $decimals);
        }
        $fraction = str_pad($parts['fraction'], $decimals, '0', STR_PAD_RIGHT);
        return self::normalize_raw($parts['whole'] . $fraction);
    }

    /**
     * Calculate ceil(local / rate * (1 + markup / 100) * 10^decimals).
     * All arithmetic is performed on unsigned decimal strings.
     *
     * @return string|false
     */
    public static function quote_to_raw($local_amount, $rate, $markup_percent, $decimals)
    {
        $decimals = (int) $decimals;
        if ($decimals < 0 || $decimals > 36) {
            return false;
        }

        $local = self::decimal_parts($local_amount, false);
        $rate_parts = self::decimal_parts($rate, false);
        $markup = self::decimal_parts($markup_percent, true);
        if (false === $local || false === $rate_parts || false === $markup
            || $local['negative'] || $rate_parts['negative']
            || '0' === $local['digits'] || '0' === $rate_parts['digits']) {
            return false;
        }

        $percent_base = '1' . str_repeat('0', $markup['scale'] + 2);
        if ($markup['negative']) {
            if (self::raw_compare($percent_base, $markup['digits']) <= 0) {
                return false;
            }
            $factor = self::raw_subtract($percent_base, $markup['digits']);
        } else {
            $factor = self::raw_add($percent_base, $markup['digits']);
        }
        if (false === $factor || '0' === $factor) {
            return false;
        }

        $numerator = self::raw_multiply($local['digits'], $factor);
        $denominator = self::raw_multiply($rate_parts['digits'], $percent_base);
        if (false === $numerator || false === $denominator) {
            return false;
        }
        $numerator = self::raw_scale10($numerator, $rate_parts['scale'] + $decimals);
        $denominator = self::raw_scale10($denominator, $local['scale']);

        return self::raw_divide_ceil($numerator, $denominator);
    }

    /** @return string|false */
    public static function raw_add($left, $right)
    {
        if (!self::is_unsigned_raw($left) || !self::is_unsigned_raw($right)) {
            return false;
        }
        $left = self::normalize_raw($left);
        $right = self::normalize_raw($right);
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        $carry = 0;
        $output = '';
        while ($i >= 0 || $j >= 0 || $carry) {
            $sum = ($i >= 0 ? (int) $left[$i--] : 0)
                + ($j >= 0 ? (int) $right[$j--] : 0) + $carry;
            $output = (string) ($sum % 10) . $output;
            $carry = (int) floor($sum / 10);
        }
        return self::normalize_raw($output);
    }

    /** @return string|false */
    public static function raw_subtract($left, $right)
    {
        if (!self::is_unsigned_raw($left) || !self::is_unsigned_raw($right)
            || self::raw_compare($left, $right) < 0) {
            return false;
        }
        $left = self::normalize_raw($left);
        $right = str_pad(self::normalize_raw($right), strlen($left), '0', STR_PAD_LEFT);
        $borrow = 0;
        $output = '';
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            $digit = (int) $left[$i] - (int) $right[$i] - $borrow;
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $output = (string) $digit . $output;
        }
        return self::normalize_raw($output);
    }

    /** @return string|false */
    public static function raw_multiply($left, $right)
    {
        if (!self::is_unsigned_raw($left) || !self::is_unsigned_raw($right)) {
            return false;
        }
        $left = self::normalize_raw($left);
        $right = self::normalize_raw($right);
        if ('0' === $left || '0' === $right) {
            return '0';
        }

        $left_length = strlen($left);
        $right_length = strlen($right);
        $digits = array_fill(0, $left_length + $right_length, 0);
        for ($i = $left_length - 1; $i >= 0; $i--) {
            for ($j = $right_length - 1; $j >= 0; $j--) {
                $digits[$i + $j + 1] += (int) $left[$i] * (int) $right[$j];
            }
        }
        for ($i = count($digits) - 1; $i > 0; $i--) {
            $digits[$i - 1] += (int) floor($digits[$i] / 10);
            $digits[$i] %= 10;
        }
        return self::normalize_raw(implode('', $digits));
    }

    /** @return string|false */
    public static function raw_scale10($raw, $places)
    {
        if (!self::is_unsigned_raw($raw) || !is_int($places) || $places < 0 || $places > 80) {
            return false;
        }
        $raw = self::normalize_raw($raw);
        return '0' === $raw ? '0' : $raw . str_repeat('0', $places);
    }

    /** @return string|false */
    public static function raw_divide_floor($numerator, $denominator)
    {
        $division = self::raw_divide($numerator, $denominator);
        return false === $division ? false : $division[0];
    }

    /** @return string|false */
    public static function raw_divide_ceil($numerator, $denominator)
    {
        $division = self::raw_divide($numerator, $denominator);
        if (false === $division) {
            return false;
        }
        return '0' === $division[1] ? $division[0] : self::raw_add($division[0], '1');
    }

    public static function raw_compare($left, $right)
    {
        if (!self::is_unsigned_raw($left) || !self::is_unsigned_raw($right)) {
            return false;
        }
        $left = self::normalize_raw($left);
        $right = self::normalize_raw($right);
        if (strlen($left) !== strlen($right)) {
            return strlen($left) > strlen($right) ? 1 : -1;
        }
        return $left === $right ? 0 : (strcmp($left, $right) > 0 ? 1 : -1);
    }

    /**
     * Render a base-unit value at a smaller customer-facing precision. The
     * conversion is exact: hidden non-zero chain decimals are never discarded.
     *
     * @return string|false
     */
    public static function raw_to_display_decimal($raw, $asset_decimals, $display_decimals = 6)
    {
        $asset_decimals = (int) $asset_decimals;
        $display_decimals = (int) $display_decimals;
        if (!self::is_unsigned_raw($raw) || $asset_decimals < 0 || $asset_decimals > 36
            || $display_decimals < 0 || $display_decimals > $asset_decimals) {
            return false;
        }
        $raw = self::normalize_raw($raw);
        $hidden = $asset_decimals - $display_decimals;
        if ($hidden > 0) {
            $padded = str_pad($raw, $hidden + 1, '0', STR_PAD_LEFT);
            if ('' !== trim(substr($padded, -$hidden), '0')) {
                return false;
            }
            $raw = substr($padded, 0, -$hidden);
        }
        return self::raw_to_decimal($raw, $display_decimals);
    }

    public static function raw_to_decimal($raw, $decimals = 6)
    {
        $decimals = max(0, min(36, (int) $decimals));
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

    private static function decimal_parts($value, $allow_negative)
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                return false;
            }
            $value = number_format($value, 18, '.', '');
        } elseif (is_int($value)) {
            $value = (string) $value;
        } elseif (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if (!preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/D', $value, $matches)) {
            return false;
        }
        $negative = '-' === $matches[1];
        if ($negative && !$allow_negative) {
            return false;
        }
        $whole = ltrim($matches[2], '0');
        $whole = '' === $whole ? '0' : $whole;
        $fraction = isset($matches[3]) ? rtrim($matches[3], '0') : '';
        $digits = self::normalize_raw($whole . $fraction);
        if ('0' === $digits) {
            $negative = false;
        }
        return array(
            'negative' => $negative,
            'whole'     => $whole,
            'fraction'  => $fraction,
            'scale'     => strlen($fraction),
            'digits'    => $digits,
        );
    }

    private static function is_unsigned_raw($raw)
    {
        return (is_string($raw) || is_int($raw))
            && strlen((string) $raw) <= 160
            && (bool) preg_match('/^[0-9]+$/D', (string) $raw);
    }

    /** @return array{0:string,1:string}|false */
    private static function raw_divide($numerator, $denominator)
    {
        if (!self::is_unsigned_raw($numerator) || !self::is_unsigned_raw($denominator)) {
            return false;
        }
        $numerator = self::normalize_raw($numerator);
        $denominator = self::normalize_raw($denominator);
        if ('0' === $denominator) {
            return false;
        }
        if (self::raw_compare($numerator, $denominator) < 0) {
            return array('0', $numerator);
        }

        $quotient = '';
        $remainder = '0';
        for ($i = 0, $length = strlen($numerator); $i < $length; $i++) {
            $remainder = self::normalize_raw(('0' === $remainder ? '' : $remainder) . $numerator[$i]);
            $low = 0;
            $high = 9;
            $digit = 0;
            while ($low <= $high) {
                $mid = (int) floor(($low + $high) / 2);
                $product = self::raw_multiply_small($denominator, $mid);
                if (self::raw_compare($product, $remainder) <= 0) {
                    $digit = $mid;
                    $low = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }
            $quotient .= (string) $digit;
            if ($digit > 0) {
                $remainder = self::raw_subtract($remainder, self::raw_multiply_small($denominator, $digit));
            }
        }
        return array(self::normalize_raw($quotient), self::normalize_raw($remainder));
    }

    private static function raw_multiply_small($raw, $multiplier)
    {
        $raw = self::normalize_raw($raw);
        $multiplier = (int) $multiplier;
        if (0 === $multiplier || '0' === $raw) {
            return '0';
        }
        $carry = 0;
        $output = '';
        for ($i = strlen($raw) - 1; $i >= 0; $i--) {
            $value = (int) $raw[$i] * $multiplier + $carry;
            $output = (string) ($value % 10) . $output;
            $carry = (int) floor($value / 10);
        }
        while ($carry > 0) {
            $output = (string) ($carry % 10) . $output;
            $carry = (int) floor($carry / 10);
        }
        return self::normalize_raw($output);
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
