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

function allstat_admin_header(string $title, string $active, array $user, array $config, ?string $help = null): void
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
    allstat_layout_start($pdo, $config, $user, [
        'title' => t($title),
        'active' => $active,
        'help' => $help,
        'legacy' => ['app', 'admin'],
        'bodyAttr' => 'data-admin-shell',
    ]);
    ?>
            <div class="page-head"><h1 class="h1"><?= h(t($title)) ?></h1></div>
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
    $labels = ['ok' => 'OK', 'warning' => t('Varování'), 'error' => t('Chyba'), 'info' => 'Info'];
    $tones = ['ok' => 'tone-up', 'warning' => 'tone-warn', 'error' => 'tone-down', 'info' => 'tone-accent'];

    return '<span class="status ' . ($tones[$status] ?? 'tone-flat') . '"><span class="dot"></span>' . h($labels[$status] ?? $status) . '</span>';
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
