<?php

require_once __DIR__ . '/../lib/layout.php';

function allstat_flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function allstat_take_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);

    return is_array($flashes) ? $flashes : [];
}

/**
 * Záhlaví stránky správy. $head (nepovinné): 'sub' = podtitul pod nadpisem, 'actions' = HTML tlačítek
 * vpravo (.btn), 'crumbs' = drobečky shellu (výchozí jen název stránky).
 */
function allstat_admin_header(string $title, string $active, array $user, array $config, ?string $help = null, array $head = []): void
{
    // Nápověda v záhlaví: kapitola návodu podle sekce, stránka může předat přesnější kotvu ('' = začátek návodu).
    $help ??= [
        'domains' => 'weby',
        'sources' => 'sluzby',
        'google' => 'google-ucty',
        'funnels' => 'trychtyre',
        'settings' => 'po-instalaci',
        'mcp' => 'ai',
        'users' => 'uzivatele',
        '2fa' => 'dvoufazove-overeni',
        'update' => 'aktualizace',
    ][$active] ?? '';
    $pdo = ($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null;
    allstat_layout_start($pdo, $config, $user, array_filter([
        'title' => t($title),
        'active' => $active,
        'help' => $help,
        'crumbs' => $head['crumbs'] ?? null,
        'bodyAttr' => 'data-admin-shell',
    ], static fn ($v): bool => $v !== null));
    ?>
            <div class="page-head">
                <div>
                    <h1 class="h1"><?= h(t($title)) ?></h1>
                    <?php if (($head['sub'] ?? '') !== ''): ?><p class="page-sub"><?= h((string) $head['sub']) ?></p><?php endif; ?>
                </div>
                <?php if (($head['actions'] ?? '') !== ''): ?><div class="page-actions"><?= $head['actions'] ?></div><?php endif; ?>
            </div>
            <?php if (function_exists('allstat_crypto_uses_default_key') && allstat_crypto_uses_default_key($config) && ($user['role'] ?? '') === 'admin'): ?>
                <div class="callout tone-down" role="alert">
                    <?= allstat_icon('shield-check', 'icon-sm') ?>
                    <div>
                        <strong><?= h(t('⚠ Kritické riziko bezpečnosti:')) ?></strong>
                        <?= h(t('Šifrovací klíč je výchozí (`allstat-local-dev-key-change-me`). Všechny tokeny v DB jsou dešifrovatelné kýmkoli s přístupem ke zdrojovému kódu.')) ?>
                        <a href="<?= h(allstat_url($config, 'admin/security.php')) ?>"><?= h(t('Rotovat klíč hned →')) ?></a>
                    </div>
                </div>
            <?php endif; ?>
            <?php foreach (allstat_take_flashes() as $flash): ?>
                <div class="callout <?= ['error' => 'tone-down', 'warn' => 'tone-warn'][$flash['type'] ?? ''] ?? 'tone-up' ?>" role="status"><?= h(t((string) ($flash['message'] ?? ''))) ?></div>
            <?php endforeach; ?>
    <?php
}

function allstat_admin_footer(array $config): void
{
    allstat_layout_end($config, ['assets/js/admin.js', 'assets/js/select-search.js', 'assets/js/table-sort.js']);
}

function allstat_status_badge(string $status): string
{
    $labels = ['ok' => t('V pořádku'), 'warning' => t('Varování'), 'error' => t('Chyba'), 'info' => 'Info', 'off' => t('Vypnuto')];

    return allstat_status_pill($status, $labels[$status] ?? $status);
}

/** Štítek stavu s vlastním textem; $status = ok | warning | error | info | pending | off. */
function allstat_status_pill(string $status, string $label): string
{
    $tones = ['ok' => 'tone-up', 'warning' => 'tone-warn', 'error' => 'tone-down', 'info' => 'tone-accent', 'pending' => 'tone-accent'];

    return '<span class="status ' . ($tones[$status] ?? 'tone-flat') . '"><span class="dot"></span>' . h($label) . '</span>';
}

/**
 * Štítek přístupu napojení (sloupec „Přístup“): Google účet, OAuth přes Meta, OAuth, API klíč.
 */
function allstat_connection_access(array $connection): string
{
    if (!empty($connection['oauth_account_id'])) {
        return t('Google účet');
    }
    if (in_array($connection['provider_key'] ?? '', ['facebook_pages', 'instagram_business', 'meta_ads'], true)) {
        return t('OAuth přes Meta');
    }

    return !empty($connection['supports_oauth']) ? 'OAuth' : t('API klíč');
}

function allstat_datetime_local(?string $value): string
{
    if (!$value) {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return '';
    }
}
