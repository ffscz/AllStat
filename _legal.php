<?php

/**
 * Shared config + layout for AllStat's PUBLIC legal pages (privacy / terms / data-deletion).
 * These pages are intentionally OUTSIDE the admin auth so the connected platforms (Meta, LinkedIn,
 * Google) and their app reviewers can always reach them.
 *
 * The org-specific values are EDITABLE from the admin (Nastavení → Právní stránky); they are stored
 * as `legal.*` rows in allstat_settings. The constants below are only the fallback defaults used when
 * a value isn't set yet or the DB is unavailable — so the pages always render something valid.
 */

const LEGAL_DEFAULTS = [
    'app'       => 'AllStat',
    'org'       => '(doplňte název organizace v Nastavení)',
    'web'       => '',
    'email'     => '',
    'address'   => '', // volitelné: sídlo správce (GDPR)
    'ico'       => '', // volitelné: IČO správce
    'effective' => '',
];

/**
 * Effective legal config = defaults overlaid with any non-empty `legal.*` settings from the DB.
 * DB failure is swallowed so the public pages never break for a reviewer.
 */
function legal_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = LEGAL_DEFAULTS;
    try {
        $config = require __DIR__ . '/config.php';
        require_once __DIR__ . '/lib/database.php';
        $pdo = allstat_db($config, true);
        if ($pdo) {
            $rows = $pdo->query("SELECT setting_key, setting_value FROM allstat_settings WHERE setting_key LIKE 'legal.%'")->fetchAll();
            foreach ($rows as $row) {
                $key = substr((string) $row['setting_key'], 6); // strip "legal."
                if (array_key_exists($key, $cfg) && trim((string) $row['setting_value']) !== '') {
                    $cfg[$key] = (string) $row['setting_value'];
                }
            }
        }
    } catch (Throwable) {
        // keep defaults
    }

    return $cfg;
}

function legal_get(string $key): string
{
    return (string) (legal_config()[$key] ?? '');
}

function legal_e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Render a full standalone HTML document with shared styling, nav and controller footer.
 * $bodyHtml is trusted, page-authored HTML (already escaped where it interpolates config).
 */
function legal_render(string $title, string $bodyHtml): void
{
    $L = legal_config();
    $app = legal_e($L['app']);
    $org = legal_e($L['org']);
    $web = legal_e($L['web']);
    $webHost = legal_e(preg_replace('#^https?://#', '', $L['web']));
    $email = legal_e($L['email']);
    $eff = legal_e($L['effective']);

    $controller = $org;
    if ($L['ico'] !== '') { $controller .= ', IČO ' . legal_e($L['ico']); }
    if ($L['address'] !== '') { $controller .= ', ' . legal_e($L['address']); }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex">';
    echo '<title>' . legal_e($title) . ', ' . $app . '</title>';
    echo '<style>
        :root{--fg:#1f2937;--muted:#6b7280;--line:#e5e7eb;--accent:#2563eb;--bg:#f8fafc;}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.65 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;}
        .wrap{max-width:820px;margin:0 auto;padding:32px 20px 64px;}
        header.top{border-bottom:1px solid var(--line);padding-bottom:16px;margin-bottom:8px;}
        .brand{font-weight:700;font-size:20px;}
        .tag{color:var(--muted);font-size:13px;margin-top:2px;}
        nav{margin:14px 0 24px;display:flex;flex-wrap:wrap;gap:8px;}
        nav a{font-size:13px;text-decoration:none;color:var(--accent);border:1px solid var(--line);background:#fff;padding:6px 10px;border-radius:8px;}
        nav a:hover{border-color:var(--accent);}
        h1{font-size:26px;margin:8px 0 4px;}
        h2{font-size:19px;margin:30px 0 8px;padding-top:6px;border-top:1px solid var(--line);}
        h3{font-size:16px;margin:18px 0 4px;}
        .lang{display:inline-block;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);background:#eef2ff;border-radius:6px;padding:2px 8px;margin:26px 0 0;}
        ul{padding-left:22px;}li{margin:4px 0;}
        code{background:#eef2f7;padding:1px 5px;border-radius:4px;font-size:90%;}
        a{color:var(--accent);}
        .eff{color:var(--muted);font-size:13px;margin-top:4px;}
        footer{margin-top:40px;border-top:1px solid var(--line);padding-top:16px;color:var(--muted);font-size:13px;}
        footer b{color:var(--fg);}
    </style></head><body><div class="wrap">';
    echo '<header class="top"><div class="brand">' . $app . '</div>';
    echo '<div class="tag">Interní analytický nástroj ' . $org . ' · Internal analytics tool (not a public service)</div></header>';
    echo '<nav>';
    echo '<a href="privacy.php">Zásady ochrany osobních údajů / Privacy</a>';
    echo '<a href="terms.php">Podmínky / Terms of Service</a>';
    echo '<a href="data-deletion.php">Smazání dat / Data deletion</a>';
    echo '<a href="' . $web . '" target="_blank" rel="noopener">' . $webHost . '</a>';
    echo '</nav>';
    echo '<main>' . $bodyHtml . '</main>';
    echo '<footer><b>' . $controller . '</b><br>';
    echo 'Správce / Data controller · ' . $org . '<br>';
    echo 'Kontakt / Contact: <a href="mailto:' . $email . '">' . $email . '</a> · Web: <a href="' . $web . '">' . $webHost . '</a><br>';
    echo 'Účinné od / Effective: ' . $eff . '</footer>';
    echo '</div></body></html>';
}
