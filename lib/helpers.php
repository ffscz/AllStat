<?php

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function allstat_number(float|int|null $value, int $decimals = 0): string
{
    return number_format((float) ($value ?? 0), $decimals, ',', ' ');
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
        return 'nesync.';
    }

    try {
        return (new DateTimeImmutable($value))->format('d.m. H:i');
    } catch (Throwable) {
        return 'nesync.';
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
    ];

    if (!isset($icons[$name])) {
        return '<i data-lucide="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"></i>';
    }

    $cls = trim('lucide ' . $class);

    return '<svg class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';
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
