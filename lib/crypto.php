<?php

const ALLSTAT_DEFAULT_ENCRYPTION_KEY = 'allstat-local-dev-key-change-me';

function allstat_crypto_keystore_path(): string
{
    return dirname(__DIR__) . '/config-keys.php';
}

function allstat_crypto_raw_keys(array $config): array
{
    $keystorePath = allstat_crypto_keystore_path();
    if (is_file($keystorePath)) {
        $keystore = require $keystorePath;
        if (is_array($keystore) && !empty($keystore['keys'])) {
            return array_values(array_map('strval', $keystore['keys']));
        }
    }

    $keys = $config['security']['encryption_keys'] ?? null;
    if (is_array($keys) && $keys !== []) {
        return array_values(array_map('strval', $keys));
    }

    return [(string) ($config['security']['encryption_key'] ?? ALLSTAT_DEFAULT_ENCRYPTION_KEY)];
}

function allstat_crypto_keys(array $config): array
{
    return array_map(static fn($k) => hash('sha256', $k, true), allstat_crypto_raw_keys($config));
}

function allstat_crypto_uses_default_key(array $config): bool
{
    $primary = allstat_crypto_raw_keys($config)[0] ?? '';
    return $primary === '' || $primary === ALLSTAT_DEFAULT_ENCRYPTION_KEY;
}

function allstat_encrypt_secret(?string $value, array $config): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $primaryKey = allstat_crypto_keys($config)[0];
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $primaryKey, OPENSSL_RAW_DATA, $iv, $tag);

    if ($ciphertext === false) {
        throw new RuntimeException('Tajnou hodnotu se nepodařilo zašifrovat.');
    }

    return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
}

function allstat_decrypt_secret(?string $value, array $config): ?string
{
    if (!$value) {
        return null;
    }

    if (!str_starts_with($value, 'enc:v1:')) {
        return $value;
    }

    $raw = base64_decode(substr($value, 7), true);

    if ($raw === false || strlen($raw) < 29) {
        return null;
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);

    foreach (allstat_crypto_keys($config) as $key) {
        $plain = @openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain !== false) {
            return $plain;
        }
    }

    return null;
}

function allstat_secret_present(?string $value): bool
{
    return is_string($value) && $value !== '';
}
