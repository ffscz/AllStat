<?php

function allstat_totp_base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $output = '';

    foreach (str_split($bytes) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }

    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }

        $output .= $alphabet[bindec($chunk)];
    }

    return $output;
}

function allstat_totp_base32_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
    $bits = '';
    $output = '';

    foreach (str_split($secret) as $char) {
        $position = strpos($alphabet, $char);

        if ($position === false) {
            continue;
        }

        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }

    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $output .= chr(bindec($chunk));
        }
    }

    return $output;
}

function allstat_totp_generate_secret(): string
{
    return allstat_totp_base32_encode(random_bytes(20));
}

function allstat_hotp(string $secret, int $counter, int $digits = 6): string
{
    $key = allstat_totp_base32_decode($secret);
    $counterBytes = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $counterBytes, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0f;
    $binary = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);

    return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

function allstat_totp_verify(string $secret, string $code, int $lastWindow = -1, ?int &$matchedWindow = null): bool
{
    $code = preg_replace('/\D+/', '', $code) ?? '';

    if (strlen($code) !== 6) {
        return false;
    }

    $currentWindow = (int) floor(time() / 30);

    for ($offset = -1; $offset <= 1; $offset++) {
        $window = $currentWindow + $offset;

        if ($window <= $lastWindow) {
            continue;
        }

        if (hash_equals(allstat_hotp($secret, $window), $code)) {
            $matchedWindow = $window;
            return true;
        }
    }

    return false;
}

function allstat_totp_uri(string $secret, string $issuer, string $account): string
{
    $label = rawurlencode($issuer . ':' . $account);

    return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
