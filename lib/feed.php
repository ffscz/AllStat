<?php

/**
 * Read-only data FEED for Google Sheets (=IMPORTDATA) and other pull consumers. A long-lived, revocable
 * per-domain token exposes ONE view's KPIs as CSV (default), TSV or JSON. Unlike the ephemeral "Share for
 * AI" links (lib/share.php), a feed has NO fixed date range — it resolves a rolling window (last 12 months,
 * YTD, …) at REQUEST time, so the sheet always pulls fresh numbers. The token is the only access control
 * (48 random hex chars, revocable); it exposes only aggregated analytics, never credentials.
 *
 * Split mirrors share.php: token/range helpers below are dependency-light (safe to call from the admin
 * _bootstrap, which does NOT load repository.php). allstat_feed_build() reads data and therefore must only
 * be called where repository.php is loaded (the public feed.php endpoint) — never from the admin page.
 */

function allstat_feed_token(): string
{
    return bin2hex(random_bytes(24)); // 48 hex chars
}

function allstat_feed_shapes(): array
{
    return ['series' => 'Časová řada (řádek na období)', 'row' => 'Souhrn (jeden řádek za celé období)'];
}

function allstat_feed_ranges(): array
{
    return [
        'last_30_days'   => 'Posledních 30 dní',
        'last_90_days'   => 'Posledních 90 dní',
        'this_month'     => 'Tento měsíc',
        'last_month'     => 'Minulý měsíc',
        'last_12_months' => 'Posledních 12 měsíců',
        'last_24_months' => 'Posledních 24 měsíců',
        'ytd'            => 'Od začátku roku (YTD)',
        'last_year'      => 'Minulý rok',
    ];
}

/** Resolve a rolling-window keyword to [start, end] at request time (no DB, no repository dependency). */
function allstat_feed_resolve_range(string $key): array
{
    $today = new DateTimeImmutable('today');
    $d = static fn (DateTimeImmutable $x): string => $x->format('Y-m-d');

    return match ($key) {
        'last_30_days'   => [$d($today->modify('-29 days')), $d($today)],
        'last_90_days'   => [$d($today->modify('-89 days')), $d($today)],
        'this_month'     => [$d($today->modify('first day of this month')), $d($today)],
        'last_month'     => [$d($today->modify('first day of last month')), $d($today->modify('last day of last month'))],
        'last_24_months' => [$d($today->modify('first day of this month')->modify('-23 months')), $d($today)],
        'ytd'            => [$today->format('Y') . '-01-01', $d($today)],
        'last_year'      => [((int) $today->format('Y') - 1) . '-01-01', ((int) $today->format('Y') - 1) . '-12-31'],
        default          => [$d($today->modify('first day of this month')->modify('-11 months')), $d($today)], // last_12_months
    };
}

function allstat_feed_create(PDO $pdo, int $domainId, int $viewSourceId, string $shape, string $granularity, string $rangeKey, string $format, string $label): array
{
    $shape = array_key_exists($shape, allstat_feed_shapes()) ? $shape : 'series';
    $granularity = in_array($granularity, ['day', 'week', 'month'], true) ? $granularity : 'month';
    $rangeKey = array_key_exists($rangeKey, allstat_feed_ranges()) ? $rangeKey : 'last_12_months';
    $format = in_array($format, ['csv', 'tsv', 'json'], true) ? $format : 'csv';
    $token = allstat_feed_token();

    $pdo->prepare('INSERT INTO feed_tokens (token, domain_id, view_source_id, shape, granularity, range_key, format, label)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$token, $domainId, $viewSourceId, $shape, $granularity, $rangeKey, $format, mb_substr($label, 0, 120)]);

    return ['token' => $token, 'format' => $format];
}

function allstat_feed_lookup(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    try {
        $row = allstat_fetch_one($pdo, 'SELECT * FROM feed_tokens WHERE token = ? AND revoked_at IS NULL LIMIT 1', [$token]);
    } catch (Throwable) {
        return null;
    }

    return $row ?: null;
}

function allstat_feed_touch(PDO $pdo, string $token): void
{
    try {
        $pdo->prepare('UPDATE feed_tokens SET last_access_at = NOW() WHERE token = ?')->execute([$token]);
    } catch (Throwable) { /* best-effort */ }
}

function allstat_feed_list(PDO $pdo): array
{
    try {
        return allstat_fetch_all($pdo, 'SELECT f.*, d.url AS domain_url FROM feed_tokens f
            LEFT JOIN domains d ON d.id = f.domain_id
            ORDER BY (f.revoked_at IS NOT NULL) ASC, f.created_at DESC');
    } catch (Throwable) {
        return [];
    }
}

function allstat_feed_revoke(PDO $pdo, int $id): void
{
    try {
        $pdo->prepare('UPDATE feed_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL')->execute([$id]);
    } catch (Throwable) { /* best-effort */ }
}

// ---------------------------------------------------------------------------
// Build — requires repository.php (allstat_query_summary / allstat_summary_row_from_values).
// Called ONLY from the public feed.php endpoint, never from the admin page.
// ---------------------------------------------------------------------------

/** Stable, ASCII, machine-readable column keys (the sheet blends on these). */
function allstat_feed_columns(): array
{
    return [
        'period', 'date_from', 'date_to', 'visits', 'users', 'new_users', 'returning_users',
        'engagement_rate', 'bounce_rate', 'avg_engagement_time_sec', 'conversions', 'conversion_rate',
        'revenue', 'clicks', 'impressions', 'ctr', 'ai_sessions', 'ai_share',
    ];
}

/** Machine-readable number: dot decimal, no thousands grouping, no symbols (the sheet formats it). */
function allstat_feed_num($value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals, '.', '');
}

function allstat_feed_row_from_summary(array $s, string $period): array
{
    return [
        'period' => $period,
        'date_from' => (string) ($s['period_start'] ?? ''),
        'date_to' => (string) ($s['period_end'] ?? ''),
        'visits' => allstat_feed_num($s['visits'] ?? 0),
        'users' => allstat_feed_num($s['users'] ?? 0),
        'new_users' => allstat_feed_num($s['new_users'] ?? 0),
        'returning_users' => allstat_feed_num($s['returning_users'] ?? 0),
        'engagement_rate' => allstat_feed_num($s['engagement_rate'] ?? 0, 2),
        'bounce_rate' => allstat_feed_num($s['bounce_rate'] ?? 0, 2),
        'avg_engagement_time_sec' => allstat_feed_num($s['avg_engagement_time'] ?? 0),
        'conversions' => allstat_feed_num($s['conversions'] ?? 0),
        'conversion_rate' => allstat_feed_num($s['conversion_rate'] ?? 0, 2),
        'revenue' => allstat_feed_num($s['revenue'] ?? 0, 2),
        'clicks' => allstat_feed_num($s['clicks'] ?? 0),
        'impressions' => allstat_feed_num($s['impressions'] ?? 0),
        'ctr' => allstat_feed_num($s['ctr'] ?? 0, 2),
        'ai_sessions' => allstat_feed_num($s['ai_sessions'] ?? 0),
        'ai_share' => allstat_feed_num($s['ai_share'] ?? 0, 2),
    ];
}

/** Per-period summary rows with ISO period_start — mirrors allstat_query_series' aggregation + revenue. */
function allstat_feed_period_rows(PDO $pdo, int $domainId, string $start, string $end, string $granularity): array
{
    $groupBy = match ($granularity) {
        'month' => 'YEAR(m.metric_date), MONTH(m.metric_date)',
        'week'  => 'YEARWEEK(m.metric_date, 3)',
        default => 'm.metric_date',
    };
    $rows = allstat_fetch_all($pdo, "
        SELECT MIN(m.metric_date) AS period_start, MAX(m.metric_date) AS period_end,
            SUM(m.visits) AS visits, SUM(m.engaged_sessions) AS engaged_sessions,
            SUM(m.engagement_time_sec) AS engagement_time_sec, SUM(m.users_count) AS users,
            SUM(m.new_users) AS new_users, SUM(m.clicks) AS clicks, SUM(m.impressions) AS impressions,
            SUM(m.conversions) AS conversions, SUM(m.revenue) AS revenue,
            SUM(COALESCE(ai.sessions, 0)) AS ai_sessions
        FROM metrics_daily m
        LEFT JOIN (
            SELECT metric_date, SUM(sessions) AS sessions FROM ai_sources_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ? GROUP BY metric_date
        ) ai ON ai.metric_date = m.metric_date
        WHERE m.domain_id = ? AND m.metric_date BETWEEN ? AND ?
        GROUP BY $groupBy
        ORDER BY period_start ASC
    ", [$domainId, $start, $end, $domainId, $start, $end]);

    return array_map(static fn (array $r): array => allstat_summary_row_from_values($r), $rows);
}

function allstat_feed_iso_week(string $date): string
{
    try {
        return (new DateTimeImmutable($date))->format('o-\WW'); // e.g. 2026-W23
    } catch (Throwable) {
        return $date;
    }
}

function allstat_feed_build(PDO $pdo, array $feed): array
{
    [$start, $end] = allstat_feed_resolve_range((string) ($feed['range_key'] ?? 'last_12_months'));
    $shape = ($feed['shape'] ?? 'series') === 'row' ? 'row' : 'series';
    $gran = in_array($feed['granularity'] ?? 'month', ['day', 'week', 'month'], true) ? (string) $feed['granularity'] : 'month';
    $domainId = (int) ($feed['domain_id'] ?? 0);

    $rows = [];
    if ($shape === 'row') {
        $s = allstat_query_summary($pdo, $domainId, $start, $end);
        $s['period_start'] = $start;
        $s['period_end'] = $end;
        $rows[] = allstat_feed_row_from_summary($s, 'celkem');
    } else {
        foreach (allstat_feed_period_rows($pdo, $domainId, $start, $end, $gran) as $pr) {
            $period = match ($gran) {
                'month' => substr((string) ($pr['period_start'] ?? ''), 0, 7),
                'week'  => allstat_feed_iso_week((string) ($pr['period_start'] ?? '')),
                default => (string) ($pr['period_start'] ?? ''),
            };
            $rows[] = allstat_feed_row_from_summary($pr, $period);
        }
    }

    return ['columns' => allstat_feed_columns(), 'rows' => $rows, 'start' => $start, 'end' => $end];
}

function allstat_feed_to_delimited(array $data, string $delimiter): string
{
    $esc = static function ($v) use ($delimiter): string {
        $v = (string) $v;
        if ($v !== '' && (str_contains($v, $delimiter) || str_contains($v, '"') || str_contains($v, "\n") || str_contains($v, "\r"))) {
            return '"' . str_replace('"', '""', $v) . '"';
        }

        return $v;
    };

    $out = implode($delimiter, array_map($esc, $data['columns'])) . "\n";
    foreach ($data['rows'] as $row) {
        $line = [];
        foreach ($data['columns'] as $col) {
            $line[] = $esc($row[$col] ?? '');
        }
        $out .= implode($delimiter, $line) . "\n";
    }

    return $out;
}
