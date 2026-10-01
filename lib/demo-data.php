<?php

function allstat_demo_domains(): array
{
    return [
        ['id' => 1, 'name' => 'Vaše doména', 'url' => 'vasedomena.cz'],
        ['id' => 2, 'name' => 'Firemní web', 'url' => 'firma.example.cz'],
        ['id' => 3, 'name' => 'Demo shop', 'url' => 'demo-shop.cz'],
    ];
}

function allstat_demo_metric_rows(int $domainId, string $start, string $end): array
{
    [$start, $end] = allstat_range($start, $end);
    $period = new DatePeriod(
        new DateTimeImmutable($start),
        new DateInterval('P1D'),
        (new DateTimeImmutable($end))->modify('+1 day')
    );
    $rows = [];

    foreach ($period as $date) {
        $day = (int) $date->format('j');
        $month = (int) $date->format('n');
        $seed = $day + ($month * 4) + ($domainId * 9);
        $base = 920 + ($domainId * 120);
        $wave = sin($seed * 0.72) * 145;
        $weekdayLift = in_array((int) $date->format('N'), [2, 3, 4], true) ? 105 : -35;
        $campaignLift = $day % 13 === 0 ? 210 : 0;
        $visits = max(240, (int) round($base + $wave + $weekdayLift + $campaignLift + (($day % 6) * 26)));
        $users = max(180, (int) round($visits * 0.72 + sin($seed * 1.1) * 42));
        $clicks = max(40, (int) round($visits * 0.095 + (($day * 7 + $domainId) % 44)));
        $impressions = max(300, (int) round($clicks * 38 + 980 + ($day * 19)));
        $conversions = max(8, (int) round($visits * 0.025 + (($day + $domainId) % 5) * 3));
        $aiSharePct = 0.03 + 0.025 * ((sin($seed * 0.9) + 1) / 2);
        $aiSessions = max(1, (int) round($visits * $aiSharePct + (($day + $domainId) % 7)));

        $rows[] = [
            'date' => $date->format('Y-m-d'),
            'visits' => $visits,
            'users' => $users,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'conversions' => $conversions,
            'ai_sessions' => $aiSessions,
            'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : 0,
        ];
    }

    return $rows;
}

function allstat_demo_sources(array $summary): array
{
    $shares = [
        'Organic Search' => 0.429,
        'Direct' => 0.25,
        'Referral' => 0.14,
        'Paid Search' => 0.09,
        'Social' => 0.091,
    ];
    $remaining = (int) $summary['visits'];
    $sources = [];
    $index = 0;

    foreach ($shares as $source => $share) {
        $index++;
        $sessions = $index === count($shares) ? $remaining : (int) round($summary['visits'] * $share);
        $remaining -= $sessions;
        $sources[] = [
            'source' => $source,
            'sessions' => max(0, $sessions),
            'conversions' => (int) round($sessions * (0.018 + ($index * 0.002))),
        ];
    }

    return $sources;
}

function allstat_demo_ai_sources(array $summary): array
{
    $shares = [
        'ChatGPT' => 0.52,
        'Perplexity' => 0.21,
        'Gemini' => 0.13,
        'Copilot' => 0.08,
        'Claude' => 0.06,
    ];
    $total = (int) ($summary['ai_sessions'] ?? 0);
    $remaining = $total;
    $sources = [];
    $index = 0;

    foreach ($shares as $source => $share) {
        $index++;
        $sessions = $index === count($shares) ? $remaining : (int) round($total * $share);
        $remaining -= $sessions;
        $sources[] = [
            'source' => $source,
            'sessions' => max(0, $sessions),
            'conversions' => (int) round($sessions * 0.03),
        ];
    }

    return allstat_decorate_ai_sources($sources);
}

function allstat_demo_referrers(array $summary): array
{
    $referralBase = max(0, (int) round(($summary['visits'] ?? 0) * 0.14));
    $shares = [
        'chatgpt.com' => 0.20,
        'facebook.com' => 0.19,
        'seznam.cz' => 0.16,
        'idnes.cz' => 0.12,
        't.co' => 0.11,
        'copilot.microsoft.com' => 0.09,
        'perplexity.ai' => 0.07,
        'linkedin.com' => 0.06,
    ];
    $remaining = $referralBase;
    $rows = [];
    $index = 0;

    foreach ($shares as $source => $share) {
        $index++;
        $sessions = $index === count($shares) ? $remaining : (int) round($referralBase * $share);
        $remaining -= $sessions;
        $rows[] = [
            'source' => $source,
            'sessions' => max(0, $sessions),
            'conversions' => (int) round($sessions * 0.02),
        ];
    }

    return allstat_decorate_referrers($rows);
}

function allstat_demo_landing_pages(array $summary): array
{
    $pages = [
        ['path' => '/', 'share' => 0.184, 'change' => 12.5],
        ['path' => '/sluzby/', 'share' => 0.089, 'change' => 8.1],
        ['path' => '/blog/', 'share' => 0.076, 'change' => 15.3],
        ['path' => '/kontakt/', 'share' => 0.051, 'change' => -3.2],
        ['path' => '/o-nas/', 'share' => 0.045, 'change' => 6.7],
    ];

    return array_map(static function (array $page) use ($summary): array {
        $sessions = (int) round($summary['visits'] * $page['share']);

        return [
            'path' => $page['path'],
            'sessions' => $sessions,
            'conversions' => (int) round($sessions * 0.024),
            'change' => $page['change'],
            'changeLabel' => allstat_change_label($page['change']),
        ];
    }, $pages);
}

function allstat_demo_queries(array $summary): array
{
    $items = [
        ['query' => 'vaše klíčové slovo', 'clickShare' => 0.131, 'ctr' => 24.3, 'position' => 2.8],
        ['query' => 'další klíčový dotaz', 'clickShare' => 0.086, 'ctr' => 19.1, 'position' => 4.2],
        ['query' => 'příklad dotazu', 'clickShare' => 0.059, 'ctr' => 17.2, 'position' => 5.7],
        ['query' => 'seo optimalizace', 'clickShare' => 0.051, 'ctr' => 19.6, 'position' => 6.1],
        ['query' => 'jak na seo', 'clickShare' => 0.04, 'ctr' => 18.8, 'position' => 7.4],
    ];

    return array_map(static function (array $item) use ($summary): array {
        $clicks = max(1, (int) round($summary['clicks'] * $item['clickShare']));
        $impressions = max($clicks, (int) round($clicks / ($item['ctr'] / 100)));

        return [
            'query' => $item['query'],
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $item['ctr'],
            'ctrLabel' => allstat_percent($item['ctr'], 1),
            'position' => $item['position'],
        ];
    }, $items);
}

function allstat_demo_source_statuses(): array
{
    return [
        ['source' => 'Google Analytics 4', 'category' => 'analytics', 'status' => 'ok', 'lastSync' => 'dnes 02:15', 'tokenExpires' => null, 'note' => ''],
        ['source' => 'Google Search Console', 'category' => 'seo', 'status' => 'ok', 'lastSync' => 'dnes 02:12', 'tokenExpires' => null, 'note' => ''],
        ['source' => 'Microsoft Clarity', 'category' => 'analytics', 'status' => 'ok', 'lastSync' => 'dnes 02:10', 'tokenExpires' => null, 'note' => ''],
        ['source' => 'Facebook Pages', 'category' => 'social', 'status' => 'ok', 'lastSync' => 'dnes 02:18', 'tokenExpires' => null, 'note' => ''],
        ['source' => 'Instagram Business', 'category' => 'social', 'status' => 'ok', 'lastSync' => 'dnes 02:18', 'tokenExpires' => null, 'note' => ''],
        ['source' => 'LinkedIn Company', 'category' => 'social', 'status' => 'warning', 'lastSync' => 'včera 23:50', 'tokenExpires' => 'brzy', 'note' => 'token expires soon'],
        ['source' => 'Google Ads', 'category' => 'ppc', 'status' => 'ok', 'lastSync' => 'dnes 02:14', 'tokenExpires' => null, 'note' => ''],
    ];
}

function allstat_group_metric_rows(array $rows, string $granularity): array
{
    $groups = [];

    foreach ($rows as $row) {
        $date = new DateTimeImmutable((string) $row['date']);
        $key = match (allstat_normalize_granularity($granularity)) {
            'month' => $date->format('Y-m'),
            'week' => $date->format('o-W'),
            default => $date->format('Y-m-d'),
        };

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'period_start' => $row['date'],
                'period_end' => $row['date'],
                'visits' => 0,
                'users' => 0,
                'clicks' => 0,
                'impressions' => 0,
                'conversions' => 0,
                'ai_sessions' => 0,
            ];
        }

        $groups[$key]['period_end'] = $row['date'];
        $groups[$key]['visits'] += (int) $row['visits'];
        $groups[$key]['users'] += (int) $row['users'];
        $groups[$key]['clicks'] += (int) $row['clicks'];
        $groups[$key]['impressions'] += (int) $row['impressions'];
        $groups[$key]['conversions'] += (int) $row['conversions'];
        $groups[$key]['ai_sessions'] += (int) ($row['ai_sessions'] ?? 0);
    }

    return array_map(static fn (array $row): array => allstat_summary_row_from_values($row), array_values($groups));
}

function allstat_demo_dashboard_data(int $domainId, string $start, string $end, string $granularity = 'day'): array
{
    [$start, $end] = allstat_range($start, $end);
    $granularity = allstat_normalize_granularity($granularity);
    $domains = allstat_demo_domains();
    $domain = $domains[0];

    foreach ($domains as $item) {
        if ((int) $item['id'] === $domainId) {
            $domain = $item;
            break;
        }
    }

    [$previousStart, $previousEnd] = allstat_previous_range($start, $end);
    $rows = allstat_demo_metric_rows((int) $domain['id'], $start, $end);
    $previousRows = allstat_demo_metric_rows((int) $domain['id'], $previousStart, $previousEnd);
    $summary = allstat_summarize_rows($rows);
    $previous = allstat_summarize_rows($previousRows);
    $series = allstat_series_from_rows(allstat_group_metric_rows($rows, $granularity), $granularity);

    return allstat_dashboard_payload(
        $domain,
        $domains,
        $start,
        $end,
        $granularity,
        $summary,
        $previous,
        $series,
        allstat_demo_sources($summary),
        allstat_demo_landing_pages($summary),
        allstat_demo_queries($summary),
        allstat_demo_source_statuses(),
        false,
        null,
        [],
        [],
        allstat_demo_ai_sources($summary),
        allstat_demo_referrers($summary)
    );
}
