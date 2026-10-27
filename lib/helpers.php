<?php

require_once __DIR__ . '/i18n.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function allstat_number(float|int|null $value, int $decimals = 0): string
{
    [$decimal, $thousands] = allstat_locale_separators();

    return number_format((float) ($value ?? 0), $decimals, $decimal, $thousands);
}

function allstat_percent(float|int|null $value, int $decimals = 1): string
{
    return allstat_number($value, $decimals) . ' %';
}

function allstat_duration_label(float|int|null $seconds): string
{
    $seconds = (int) round((float) ($seconds ?? 0));

    if ($seconds <= 0) {
        return '0 s';
    }
    if ($seconds < 60) {
        return $seconds . ' s';
    }

    $minutes = intdiv($seconds, 60);
    $rest = $seconds % 60;

    if ($minutes < 60) {
        return $rest > 0 ? $minutes . ' min ' . $rest . ' s' : $minutes . ' min';
    }

    $hours = intdiv($minutes, 60);
    $minutes %= 60;

    return $minutes > 0 ? $hours . ' h ' . $minutes . ' min' : $hours . ' h';
}

function allstat_normalize_email(string $email): string
{
    $email = trim($email);
    $normalized = preg_replace('/[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+/u', '', $email);

    if (is_string($normalized)) {
        $email = $normalized;
    }

    return strtolower($email);
}

function allstat_is_valid_email(string $email): bool
{
    $email = allstat_normalize_email($email);

    if ($email === '' || strlen($email) > 190 || substr_count($email, '@') !== 1) {
        return false;
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
        return true;
    }

    return preg_match('/^[a-z0-9._%+\-]+@[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)+$/i', $email) === 1;
}

function allstat_normalize_date(?string $value, string $fallback): string
{
    if (!$value) {
        return $fallback;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return $fallback;
    }

    return $value;
}

function allstat_range(string $start, string $end): array
{
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);

    if ($endDate < $startDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }

    return [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')];
}

function allstat_limited_range(string $start, string $end, int $maxDays = 730): array
{
    [$start, $end] = allstat_range($start, $end);
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
    $maxDays = max(1, $maxDays);

    if ($startDate->diff($endDate)->days + 1 > $maxDays) {
        $startDate = $endDate->modify('-' . ($maxDays - 1) . ' days');
    }

    return [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')];
}

/**
 * Srovnávací období. Kalendářní období se srovnávají kalendářně: celé měsíce (září) s předchozími celými
 * měsíci (celý srpen), rozběhnutý měsíc (1.–5. 10.) se stejnými dny minulého měsíce a rozběhnutý rok se
 * stejnými dny loni. Ostatní období (posledních 30 dní, vlastní rozsah) s obdobím stejné délky těsně před.
 */
function allstat_previous_range(string $start, string $end): array
{
    [$start, $end] = allstat_range($start, $end);
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);

    if ($startDate->format('j') === '1') {
        $endsMonth = $endDate->format('Y-m-d') === $endDate->modify('last day of this month')->format('Y-m-d');
        $runningYear = $startDate->format('n') === '1' && $startDate->format('Y') === $endDate->format('Y')
            && $endDate->format('m-d') !== '12-31' && $endDate >= new DateTimeImmutable('yesterday');
        if ($endsMonth && !$runningYear) {
            // Celé kalendářní měsíce (i celý rok): stejný počet měsíců těsně před.
            $months = ((int) $endDate->format('Y') - (int) $startDate->format('Y')) * 12 + (int) $endDate->format('n') - (int) $startDate->format('n') + 1;
            $previousStart = $startDate->modify('-' . $months . ' months');

            return [$previousStart->format('Y-m-d'), $startDate->modify('-1 day')->format('Y-m-d')];
        }
        if ($startDate->format('Y-m') === $endDate->format('Y-m') && !$endsMonth) {
            // Rozběhnutý měsíc: stejné dny minulého měsíce (31. se u kratšího měsíce zkrátí na jeho konec).
            $previousStart = $startDate->modify('first day of last month');
            $previousEnd = $previousStart->setDate((int) $previousStart->format('Y'), (int) $previousStart->format('n'), min((int) $endDate->format('j'), (int) $previousStart->format('t')));

            return [$previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d')];
        }
        if ($runningYear) {
            // Rozběhnutý rok (od 1. 1. do včerejška či dneška): stejné dny loni (29. 2. se zkrátí na 28. 2.).
            $year = (int) $startDate->format('Y') - 1;
            $day = min((int) $endDate->format('j'), (int) $endDate->setDate($year, (int) $endDate->format('n'), 1)->format('t'));

            return [$startDate->setDate($year, 1, 1)->format('Y-m-d'), $endDate->setDate($year, (int) $endDate->format('n'), $day)->format('Y-m-d')];
        }
    }

    $days = $startDate->diff($endDate)->days + 1;
    $previousEnd = $startDate->modify('-1 day');
    $previousStart = $previousEnd->modify('-' . ($days - 1) . ' days');

    return [$previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d')];
}

function allstat_change(float|int $current, float|int $previous): ?float
{
    if ((float) $previous === 0.0) {
        return null;
    }

    return (((float) $current - (float) $previous) / abs((float) $previous)) * 100;
}

function allstat_change_label(?float $change): string
{
    if ($change === null) {
        return '—';
    }
    $prefix = $change > 0 ? '+' : '';

    return $prefix . allstat_number($change, 1) . ' %';
}

function allstat_iso_to_cz(?string $value): string
{
    if (!$value) {
        return t('nesync.');
    }

    try {
        return (new DateTimeImmutable($value))->format(allstat_locale() === 'en' ? 'j M H:i' : 'd.m. H:i');
    } catch (Throwable) {
        return t('nesync.');
    }
}

function allstat_json(mixed $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}

/**
 * Render a Lucide icon as inline SVG server-side, so it's painted immediately (no flash while the
 * Lucide JS swaps <i data-lucide> placeholders on every full page load). Unknown icons fall back to
 * the JS placeholder, so nothing regresses. Used for the sidebar menu (the part users click most).
 */
function allstat_icon(string $name, string $class = ''): string
{
    static $icons = [
        'home' => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'globe-2' => '<path d="M21.54 15H17a2 2 0 0 0-2 2v4.54"/><path d="M7 3.34V5a3 3 0 0 0 3 3a2 2 0 0 1 2 2c0 1.1.9 2 2 2a2 2 0 0 0 2-2c0-1.1.9-2 2-2h3.17"/><path d="M11 21.95V18a2 2 0 0 0-2-2a2 2 0 0 1-2-2v-1a2 2 0 0 0-2-2H2.05"/><circle cx="12" cy="12" r="10"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
        'line-chart' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/>',
        'clipboard-list' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'sheet' => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><line x1="3" x2="21" y1="9" y2="9"/><line x1="3" x2="21" y1="15" y2="15"/><line x1="9" x2="9" y1="9" y2="21"/><line x1="15" x2="15" y1="9" y2="21"/>',
        'settings' => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/>',
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'log-out' => '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
        'menu' => '<path d="M4 5h16"/><path d="M4 12h16"/><path d="M4 19h16"/>',
        'moon' => '<path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'circle-help' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        'layout-grid' => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        'filter' => '<path d="M10 20a1 1 0 0 0 .553.895l2 1A1 1 0 0 0 14 21v-7a2 2 0 0 1 .517-1.341L21.74 4.67A1 1 0 0 0 21 3H3a1 1 0 0 0-.742 1.67l7.225 7.989A2 2 0 0 1 10 14z"/>',
        'key-round' => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
        'plug-zap' => '<path d="M6.3 20.3a2.4 2.4 0 0 0 3.4 0L12 18l-6-6-2.3 2.3a2.4 2.4 0 0 0 0 3.4Z"/><path d="m2 22 3-3"/><path d="M7.5 13.5 10 11"/><path d="M10.5 16.5 13 14"/><path d="m18 3-4 4h6l-4 4"/>',
        'chevrons-up-down' => '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'languages' => '<path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/>',
        'download' => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
        'sparkles' => '<path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/>',
        'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'search' => '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>',
        'refresh-cw' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'ellipsis' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'trending-up' => '<path d="M16 7h6v6"/><path d="m22 7-8.5 8.5-5-5L2 17"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'megaphone' => '<path d="M11 6a13 13 0 0 0 8.4-2.8A1 1 0 0 1 21 4v12a1 1 0 0 1-1.6.8A13 13 0 0 0 11 14H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/><path d="M6 14a12 12 0 0 0 2.4 7.2 2 2 0 0 0 3.2-2.4A8 8 0 0 1 10 14"/><path d="M8 6v8"/>',
        'share-2' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/>',
        'activity' => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
    ];

    if (!isset($icons[$name])) {
        return '<i data-lucide="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' . ($class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '') . '></i>';
    }

    $cls = trim('lucide ' . $class);

    return '<svg class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';
}

/**
 * Návod k AllStatu: web na GitHub Pages, stejný pro všechny instalace (zdroj je větev gh-pages).
 * Kotvy jsou id kapitol a podkapitol návodu; při přejmenování nadpisu tam id zůstává. Anglická verze je v en/
 * se stejnými kotvami; slovenské rozhraní vede na český návod (je Slovákům bližší než anglický).
 */
const ALLSTAT_DOCS_URL = 'https://ffscz.github.io/AllStat/';

function allstat_docs_url(string $anchor = ''): string
{
    return ALLSTAT_DOCS_URL . (allstat_locale() === 'en' ? 'en/' : '') . ($anchor !== '' ? '#' . rawurlencode($anchor) : '');
}

/** Textový odkaz na kapitolu návodu (nová karta). */
function allstat_help_link(string $anchor, string $label): string
{
    return '<a class="help-link" href="' . h(allstat_docs_url($anchor)) . '" target="_blank" rel="noopener">' . h($label) . '</a>';
}

/** Otazník v záhlaví stránky: otevře kapitolu návodu, která ke stránce patří ('' = začátek návodu). */
function allstat_help_button(string $anchor = ''): string
{
    return '<a class="btn-icon" href="' . h(allstat_docs_url($anchor)) . '" target="_blank" rel="noopener" title="' . h(t('Nápověda: návod na webu')) . '"'
        . ' aria-label="' . h(t('Nápověda, návod se otevře v nové kartě')) . '">' . allstat_icon('circle-help', 'icon') . '</a>';
}

/** Podkapitola návodu o napojení dané služby (klíč z data_sources.provider_key). */
function allstat_help_anchor_for_provider(string $providerKey): string
{
    return match ($providerKey) {
        'ga4', 'gsc', 'youtube' => 'google',
        'facebook_pages', 'instagram_business', 'meta_ads' => 'meta',
        'linkedin_company' => 'linkedin',
        'google_ads' => 'google-ads',
        'microsoft_ads' => 'microsoft-ads',
        'clarity' => 'clarity',
        default => 'sluzby',
    };
}

/**
 * Malý inline SVG spojnicový graf (trend) z číselné série. Bezstavový, bez JS — vhodné do
 * server-renderovaných provider views (přepočítá se při reloadu stránky). Šířka se roztáhne
 * na 100 % kontejneru (preserveAspectRatio=none, osa x = čas), výška dle $height. Barva = hex.
 */
function allstat_trend_svg(array $series, string $color = '#2563eb', int $height = 44): string
{
    $vals = array_values(array_map(static fn ($v): float => (float) $v, $series));
    $n = count($vals);
    if ($n === 0) {
        return '';
    }
    if ($n === 1) {
        $vals = [$vals[0], $vals[0]];
        $n = 2;
    }
    $min = min($vals);
    $max = max($vals);
    $span = $max - $min;
    $pad = 3.0;
    $usable = $height - 2 * $pad;
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = round($i / ($n - 1) * 100, 2);
        $y = $span > 0 ? round($pad + ($max - $v) / $span * $usable, 2) : round($height / 2, 2);
        $pts[] = $x . ',' . $y;
    }
    $line = implode(' ', $pts);
    $area = '0,' . $height . ' ' . $line . ' 100,' . $height;
    $c = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');

    return '<svg class="trend-svg" viewBox="0 0 100 ' . $height . '" preserveAspectRatio="none" role="img" aria-hidden="true" focusable="false">'
        . '<polygon points="' . $area . '" fill="' . $c . '" fill-opacity="0.12" stroke="none"/>'
        . '<polyline points="' . $line . '" fill="none" stroke="' . $c . '" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/>'
        . '</svg>';
}

/**
 * Horizontální bar graf jako HTML (reuse .bar-chart / .bar-row). $rows = [['label','value','share'?], …].
 * Šířka pruhu = poměr k nejsilnější položce. $percent=true → hodnota už JE procento (FB demografie z CSV),
 * zobrazí se „X %"; jinak počet + podíl z celku v závorce (IG demografie z API = počty).
 */
function allstat_bar_rows_html(array $rows, array $palette, bool $percent = false): string
{
    if (!$rows) {
        return '';
    }
    $palette = $palette ?: ['#2563eb'];
    $max = max(1e-9, ...array_map(static fn ($r) => (float) ($r['value'] ?? 0), $rows));
    $tot = array_sum(array_map(static fn ($r) => (float) ($r['value'] ?? 0), $rows));
    $out = '';
    $i = 0;
    foreach ($rows as $r) {
        $v = (float) ($r['value'] ?? 0);
        $w = round(max(2, $v / $max * 100), 1);
        $label = htmlspecialchars((string) ($r['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($percent) {
            $valueHtml = '<strong>' . allstat_number($v, 1) . ' %</strong>';
        } else {
            $share = isset($r['share']) ? (int) round((float) $r['share']) : ($tot > 0 ? (int) round($v / $tot * 100) : 0);
            $valueHtml = '<strong>' . allstat_number($v) . '</strong> <small>(' . $share . ' %)</small>';
        }
        $out .= '<div class="bar-row"><span class="bar-row-label" title="' . $label . '">' . $label . '</span>'
            . '<div class="bar-row-track"><div class="bar-row-fill" style="width: ' . $w . '%; background: ' . $palette[$i % count($palette)] . ';"></div></div>'
            . '<span class="bar-row-value">' . $valueHtml . '</span></div>';
        $i++;
    }

    return '<div class="bar-chart">' . $out . '</div>';
}
