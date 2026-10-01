<?php

function allstat_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function allstat_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(allstat_csrf_token()) . '">';
}

function allstat_csrf_check(): void
{
    $token = $_POST['csrf'] ?? '';

    if (!is_string($token) || !hash_equals(allstat_csrf_token(), $token)) {
        http_response_code(419);
        exit('Neplatný bezpečnostní token. Zkuste formulář odeslat znovu.');
    }
}
