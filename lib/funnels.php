<?php

/**
 * Trychtýře (2026-10-01): konfigurovatelné konverzní trychtýře z GA4 eventů.
 *
 * Soubor načítá index.php, admin stránka, MCP i sync (require_once) a při načtení nic nedělá, jen definuje funkce.
 * Počty kroků = SUM(event_count) z events_daily (ta má celou historii a plní se vždy). Rozpad podle kanálu /
 * zdroje a média / kampaně = events_source_daily (plní GA4 sync jen pro eventy z AKTIVNÍCH trychtýřů, takže
 * historie rozpadu začíná až od prvního syncu po vytvoření trychtýře). Všechny dotazy filtrují domain_id.
 * Počítají se EVENTY (ne unikátní návštěvníci), proto může být pozdější krok vyšší než předchozí.
 */

/** Limity formuláře trychtýře (UI je zobrazuje, allstat_funnel_save je vynucuje). */
function allstat_funnel_limits(): array
{
    return [
        'min_steps' => 2,
        'max_steps' => 10,
        'max_funnels' => 20,
        'name_length' => 120,
        'label_length' => 60,
        'event_length' => 40,
    ];
}

/** Hotové šablony pro rychlé založení trychtýře (kroky = [label, GA4 event]). */
function allstat_funnel_templates(): array
{
    return [
        'quick_check' => [
            'name' => 'Quick Check (lead)',
            'description' => 'Cesta návštěvníka od vstupu na web přes Quick Check a zobrazení skóre až po kliknutí na výzvu a lead.',
            'steps' => [
                ['label' => 'Návštěva webu', 'event' => 'session_start'],
                ['label' => 'Spuštění Quick Checku', 'event' => 'quick_check_start'],
                ['label' => 'Dokončení', 'event' => 'quick_check_complete'],
                ['label' => 'Zobrazení skóre', 'event' => 'score_view'],
                ['label' => 'Klik na CTA', 'event' => 'cta_click'],
                ['label' => 'Lead', 'event' => 'generate_lead'],
            ],
        ],
        'lead_form' => [
            'name' => 'Poptávkový formulář (lead)',
            'description' => 'Návštěva webu, zahájení a odeslání formuláře a výsledný lead.',
            'steps' => [
                ['label' => 'Návštěva webu', 'event' => 'session_start'],
                ['label' => 'Zahájení formuláře', 'event' => 'form_start'],
                ['label' => 'Odeslání formuláře', 'event' => 'form_submit'],
                ['label' => 'Lead', 'event' => 'generate_lead'],
            ],
        ],
        'ecommerce' => [
            'name' => 'E-shop (nákup)',
            'description' => 'Nákupní cesta od návštěvy přes produkt, košík a objednávku až po nákup.',
            'steps' => [
                ['label' => 'Návštěva webu', 'event' => 'session_start'],
                ['label' => 'Zobrazení produktu', 'event' => 'view_item'],
                ['label' => 'Přidání do košíku', 'event' => 'add_to_cart'],
                ['label' => 'Zahájení objednávky', 'event' => 'begin_checkout'],
                ['label' => 'Zadání platby', 'event' => 'add_payment_info'],
                ['label' => 'Nákup', 'event' => 'purchase'],
            ],
        ],
    ];
}

/** Dostupné rozpady (klíč => popisek). */
function allstat_funnel_breakdowns(): array
{
    return [
        'channel' => 'Kanál',
        'source_medium' => 'Zdroj / médium',
        'campaign' => 'Kampaň',
    ];
}

/** Řádek z allstat_funnels → pole s dekódovanými kroky (poškozené JSON = prázdné kroky). */
function allstat_funnel_hydrate(array $row): array
{
    $steps = [];
    $decoded = json_decode((string) ($row['steps_json'] ?? ''), true);
    if (is_array($decoded)) {
        foreach ($decoded as $step) {
            if (!is_array($step)) { continue; }
            $event = trim((string) ($step['event'] ?? ''));
            if ($event === '') { continue; }
            $label = trim((string) ($step['label'] ?? ''));
            $steps[] = ['label' => $label !== '' ? $label : $event, 'event' => $event];
        }
    }

    return [
        'id' => (int) $row['id'],
        'domain_id' => (int) $row['domain_id'],
        'name' => (string) $row['name'],
        'breakdown' => (string) $row['breakdown'],
        'is_active' => (int) $row['is_active'] === 1,
        'sort_order' => (int) $row['sort_order'],
        'steps' => $steps,
    ];
}

/** Trychtýře webu seřazené podle sort_order, id (výchozí jen aktivní). */
function allstat_funnels_for_domain(PDO $pdo, int $domainId, bool $activeOnly = true): array
{
    $rows = allstat_fetch_all($pdo, '
        SELECT id, domain_id, name, steps_json, breakdown, is_active, sort_order
        FROM allstat_funnels
        WHERE domain_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . '
        ORDER BY sort_order ASC, id ASC
    ', [$domainId]);

    return array_map('allstat_funnel_hydrate', $rows);
}

/** Jeden trychtýř; null když neexistuje NEBO patří jinému webu (izolace webů). */
function allstat_funnel_get(PDO $pdo, int $funnelId, int $domainId): ?array
{
    $row = allstat_fetch_one($pdo, '
        SELECT id, domain_id, name, steps_json, breakdown, is_active, sort_order
        FROM allstat_funnels
        WHERE id = ? AND domain_id = ?
    ', [$funnelId, $domainId]);

    return $row ? allstat_funnel_hydrate($row) : null;
}

/**
 * Ověří a vyčistí vstup formuláře. Vrací [chyba|null, vyčištěná data].
 * Prázdné řádky kroků (label i event prázdné) se přeskočí.
 */
function allstat_funnel_clean_input(array $input): array
{
    $limits = allstat_funnel_limits();

    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) ($input['name'] ?? ''))) ?? '';
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > $limits['name_length']) {
        return ['Zadejte název trychtýře (1 až ' . $limits['name_length'] . ' znaků).', []];
    }

    $breakdown = (string) ($input['breakdown'] ?? 'channel');
    if (!array_key_exists($breakdown, allstat_funnel_breakdowns())) {
        return ['Neplatný způsob rozpadu trychtýře.', []];
    }

    $steps = [];
    $rawSteps = $input['steps'] ?? [];
    foreach (is_array($rawSteps) ? array_values($rawSteps) : [] as $index => $raw) {
        $position = $index + 1;
        if (!is_array($raw)) {
            return ['Krok ' . $position . ' má neplatný tvar.', []];
        }
        $event = trim((string) ($raw['event'] ?? ''));
        $label = trim((string) ($raw['label'] ?? ''));
        if ($event === '' && $label === '') {
            continue;
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,' . ($limits['event_length'] - 1) . '}$/', $event)) {
            return ['Krok ' . $position . ': název eventu musí začínat písmenem a smí obsahovat jen písmena, číslice a podtržítko (nejvýše ' . $limits['event_length'] . ' znaků).', []];
        }
        $label = preg_replace('/[\x00-\x1F\x7F]/u', '', $label) ?? '';
        $label = mb_substr(trim($label), 0, $limits['label_length']);
        $steps[] = ['label' => $label !== '' ? $label : $event, 'event' => $event];
    }

    if (count($steps) < $limits['min_steps'] || count($steps) > $limits['max_steps']) {
        return ['Trychtýř musí mít ' . $limits['min_steps'] . ' až ' . $limits['max_steps'] . ' kroků (zadáno ' . count($steps) . ').', []];
    }

    return [null, ['name' => $name, 'breakdown' => $breakdown, 'steps' => $steps]];
}

/**
 * Založí (funnelId = null) nebo upraví trychtýř webu.
 * $input = ['name', 'breakdown', 'is_active' (bool|'1'|'0'), 'steps' => [['label', 'event'], …], volitelně 'sort_order'].
 * Vrací ['ok' => bool, 'id' => int, 'message' => string].
 */
function allstat_funnel_save(PDO $pdo, int $domainId, ?int $funnelId, array $input): array
{
    $limits = allstat_funnel_limits();
    $fail = static fn (string $message): array => ['ok' => false, 'id' => (int) $funnelId, 'message' => $message];

    if (!allstat_fetch_one($pdo, 'SELECT id FROM domains WHERE id = ?', [$domainId])) {
        return $fail('Web neexistuje.');
    }

    $existing = null;
    if ($funnelId !== null) {
        $existing = allstat_funnel_get($pdo, $funnelId, $domainId);
        if (!$existing) {
            return $fail('Trychtýř neexistuje nebo patří jinému webu.');
        }
    }

    [$error, $clean] = allstat_funnel_clean_input($input);
    if ($error !== null) {
        return $fail($error);
    }

    if (array_key_exists('is_active', $input)) {
        $isActive = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    } else {
        $isActive = $existing ? (int) $existing['is_active'] : 1;
    }
    $stepsJson = json_encode($clean['steps'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        if ($existing) {
            $sortOrder = isset($input['sort_order']) ? (int) $input['sort_order'] : (int) $existing['sort_order'];
            $pdo->prepare('
                UPDATE allstat_funnels
                SET name = ?, steps_json = ?, breakdown = ?, is_active = ?, sort_order = ?
                WHERE id = ? AND domain_id = ?
            ')->execute([$clean['name'], $stepsJson, $clean['breakdown'], $isActive, $sortOrder, (int) $funnelId, $domainId]);

            return ['ok' => true, 'id' => (int) $funnelId, 'message' => 'Trychtýř byl uložen.'];
        }

        $stats = allstat_fetch_one($pdo, 'SELECT COUNT(*) AS total, COALESCE(MAX(sort_order), 0) AS max_sort FROM allstat_funnels WHERE domain_id = ?', [$domainId]);
        if ((int) ($stats['total'] ?? 0) >= $limits['max_funnels']) {
            return $fail('Web může mít nejvýše ' . $limits['max_funnels'] . ' trychtýřů.');
        }
        $sortOrder = isset($input['sort_order']) ? (int) $input['sort_order'] : (int) ($stats['max_sort'] ?? 0) + 10;
        $pdo->prepare('
            INSERT INTO allstat_funnels (domain_id, name, steps_json, breakdown, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$domainId, $clean['name'], $stepsJson, $clean['breakdown'], $isActive, $sortOrder]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'message' => 'Trychtýř byl vytvořen. Rozpad podle zdrojů se začne plnit při příští synchronizaci GA4.'];
    } catch (Throwable $exception) {
        error_log('AllStat funnel save: ' . $exception->getMessage());

        return $fail('Trychtýř se nepodařilo uložit.');
    }
}

/** Smaže trychtýř webu; false když neexistuje nebo patří jinému webu. */
function allstat_funnel_delete(PDO $pdo, int $funnelId, int $domainId): bool
{
    $statement = $pdo->prepare('DELETE FROM allstat_funnels WHERE id = ? AND domain_id = ?');
    $statement->execute([$funnelId, $domainId]);

    return $statement->rowCount() > 0;
}

/** Eventy webu za posledních N dní z events_daily (pro výběr kroků): [['event', 'count', 'last_date'], …] podle počtu sestupně. */
function allstat_domain_event_names(PDO $pdo, int $domainId, int $days = 90): array
{
    $days = max(1, min(1095, $days));
    $since = (new DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');

    $rows = allstat_fetch_all($pdo, '
        SELECT event_name, SUM(event_count) AS total, MAX(metric_date) AS last_date
        FROM events_daily
        WHERE domain_id = ? AND metric_date >= ?
        GROUP BY event_name
        ORDER BY total DESC, event_name ASC
        LIMIT 500
    ', [$domainId, $since]);

    return array_map(static fn (array $row): array => [
        'event' => (string) $row['event_name'],
        'count' => (int) $row['total'],
        'last_date' => (string) $row['last_date'],
    ], $rows);
}

/** Unikátní eventy ze všech AKTIVNÍCH trychtýřů webu (pro GA4 sync), v pořadí výskytu. */
function allstat_funnel_events_for_domain(PDO $pdo, int $domainId): array
{
    $events = [];
    foreach (allstat_funnels_for_domain($pdo, $domainId, true) as $funnel) {
        foreach ($funnel['steps'] as $step) {
            $key = strtolower($step['event']);
            if (!isset($events[$key])) {
                $events[$key] = $step['event'];
            }
        }
    }

    return array_values($events);
}

/** Počet výskytů každého eventu za posledních N dní (včetně dneška): ['event' => počet], 0 když nic nepřišlo. */
function allstat_funnel_step_activity(PDO $pdo, int $domainId, array $events, int $days = 28): array
{
    $result = [];
    foreach ($events as $event) {
        $event = trim((string) $event);
        if ($event !== '') {
            $result[$event] = 0;
        }
    }
    if (!$result) {
        return [];
    }

    $days = max(1, min(1095, $days));
    $since = (new DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $names = array_keys($result);
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $rows = allstat_fetch_all($pdo, "
        SELECT event_name, SUM(event_count) AS total
        FROM events_daily
        WHERE domain_id = ? AND metric_date >= ? AND event_name IN ($placeholders)
        GROUP BY event_name
    ", array_merge([$domainId, $since], $names));

    $byLower = [];
    foreach ($rows as $row) {
        $key = strtolower((string) $row['event_name']);
        $byLower[$key] = ($byLower[$key] ?? 0) + (int) $row['total'];
    }
    foreach ($names as $name) {
        $result[$name] = $byLower[strtolower($name)] ?? 0;
    }

    return $result;
}

/** 'Y-m-d' → DateTimeImmutable (půlnoc) nebo null při neplatném datu. */
function allstat_funnel_parse_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

    return ($date && $date->format('Y-m-d') === trim($value)) ? $date : null;
}

/** Součty eventů z events_daily za období: [malý název eventu => počet]. */
function allstat_funnel_event_totals(PDO $pdo, int $domainId, array $events, string $start, string $end): array
{
    if (!$events) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($events), '?'));
    $rows = allstat_fetch_all($pdo, "
        SELECT event_name, SUM(event_count) AS total
        FROM events_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND event_name IN ($placeholders)
        GROUP BY event_name
    ", array_merge([$domainId, $start, $end], $events));

    $totals = [];
    foreach ($rows as $row) {
        $key = strtolower((string) $row['event_name']);
        $totals[$key] = ($totals[$key] ?? 0) + (int) $row['total'];
    }

    return $totals;
}

/** Popisek řádku rozpadu; prázdné / '(not set)' → 'Neurčeno' (u kampaně 'Bez kampaně'). */
function allstat_funnel_breakdown_label(string $breakdown, array $row): string
{
    $clean = static function (mixed $value): string {
        $value = trim((string) $value);

        return ($value === '' || strtolower($value) === '(not set)') ? '' : $value;
    };

    if ($breakdown === 'campaign') {
        $campaign = $clean($row['campaign'] ?? '');

        return $campaign !== '' ? $campaign : 'Bez kampaně';
    }
    if ($breakdown === 'source_medium') {
        $source = $clean($row['source'] ?? '');
        $medium = $clean($row['medium'] ?? '');
        if ($source === '' && $medium === '') {
            return 'Neurčeno';
        }

        return ($source !== '' ? $source : '(not set)') . ' / ' . ($medium !== '' ? $medium : '(not set)');
    }

    $channel = $clean($row['channel'] ?? '');

    return $channel !== '' ? $channel : 'Neurčeno';
}

/**
 * Rozpad kroků podle kanálu / zdroje a média / kampaně z events_source_daily.
 * Vrací ['key', 'label', 'available', 'coveredFrom', 'rows' => [['label', 'counts', 'overall'], …]].
 */
function allstat_funnel_breakdown_report(PDO $pdo, int $domainId, array $steps, string $breakdown, string $start, string $end): array
{
    $labels = allstat_funnel_breakdowns();
    $result = [
        'key' => $breakdown,
        'label' => $labels[$breakdown] ?? $breakdown,
        'available' => false,
        'coveredFrom' => null,
        'rows' => [],
    ];

    $events = [];
    foreach ($steps as $step) {
        $events[strtolower($step['event'])] = $step['event'];
    }
    if (!$events) {
        return $result;
    }

    // Sloupce pro GROUP BY jsou pevný whitelist, nikdy vstup od uživatele.
    $columns = match ($breakdown) {
        'source_medium' => ['source', 'medium'],
        'campaign' => ['campaign'],
        default => ['channel'],
    };
    $columnSql = implode(', ', $columns);
    $placeholders = implode(',', array_fill(0, count($events), '?'));
    $eventParams = array_values($events);

    try {
        $first = allstat_fetch_one($pdo, "
            SELECT MIN(metric_date) AS first_date
            FROM events_source_daily
            WHERE domain_id = ? AND event_name IN ($placeholders)
        ", array_merge([$domainId], $eventParams));
        $result['coveredFrom'] = !empty($first['first_date']) ? (string) $first['first_date'] : null;

        $rows = allstat_fetch_all($pdo, "
            SELECT event_name, $columnSql, SUM(event_count) AS total
            FROM events_source_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND event_name IN ($placeholders)
            GROUP BY event_name, $columnSql
        ", array_merge([$domainId, $start, $end], $eventParams));
    } catch (Throwable $exception) {
        error_log('AllStat funnel breakdown: ' . $exception->getMessage());

        return $result;
    }

    if (!$rows) {
        return $result;
    }

    // Normalizace popisků může sloučit víc řádků z DB ('' a '(not set)'), proto se sčítá znovu v PHP.
    $groups = [];
    foreach ($rows as $row) {
        $label = allstat_funnel_breakdown_label($breakdown, $row);
        $key = mb_strtolower($label);
        $groups[$key]['label'] = $groups[$key]['label'] ?? $label;
        $event = strtolower((string) $row['event_name']);
        $groups[$key]['events'][$event] = ($groups[$key]['events'][$event] ?? 0) + (int) $row['total'];
    }

    $table = [];
    foreach ($groups as $group) {
        $counts = [];
        foreach ($steps as $step) {
            $counts[] = (int) ($group['events'][strtolower($step['event'])] ?? 0);
        }
        $table[] = ['label' => $group['label'], 'counts' => $counts];
    }

    usort($table, static function (array $a, array $b): int {
        return [$b['counts'][0], array_sum($b['counts']), $a['label']] <=> [$a['counts'][0], array_sum($a['counts']), $b['label']];
    });

    $visible = array_slice($table, 0, 12);
    $rest = array_slice($table, 12);
    if ($rest) {
        $others = array_fill(0, count($steps), 0);
        foreach ($rest as $row) {
            foreach ($row['counts'] as $i => $count) {
                $others[$i] += $count;
            }
        }
        $visible[] = ['label' => 'Ostatní', 'counts' => $others];
    }

    foreach ($visible as $row) {
        $firstCount = $row['counts'][0] ?? 0;
        $lastCount = $row['counts'][count($row['counts']) - 1] ?? 0;
        $result['rows'][] = [
            'label' => $row['label'],
            'counts' => $row['counts'],
            'overall' => (count($steps) >= 2 && $firstCount > 0) ? $lastCount / (float) $firstCount : null,
        ];
    }
    $result['available'] = true;

    return $result;
}

/** Vývoj kroků v čase z events_daily: den při rozsahu do 31 dní, jinak týden (pondělí). Labels jsou 'Y-m-d' (začátek období). */
function allstat_funnel_trend_report(PDO $pdo, int $domainId, array $steps, DateTimeImmutable $startDate, DateTimeImmutable $endDate): array
{
    $days = (int) $startDate->diff($endDate)->days + 1;
    $granularity = $days <= 31 ? 'day' : 'week';

    $bucketOf = static function (DateTimeImmutable $date) use ($granularity): string {
        if ($granularity === 'week') {
            $date = $date->modify('-' . ((int) $date->format('N') - 1) . ' days');
        }

        return $date->format('Y-m-d');
    };

    $labels = [];
    $cursor = $startDate;
    while ($cursor <= $endDate) {
        $bucket = $bucketOf($cursor);
        if (!in_array($bucket, $labels, true)) {
            $labels[] = $bucket;
        }
        $cursor = $cursor->modify('+1 day');
    }
    $position = array_flip($labels);

    $series = [];
    foreach ($steps as $step) {
        $series[] = array_fill(0, count($labels), 0);
    }

    $events = [];
    foreach ($steps as $step) {
        $events[strtolower($step['event'])] = $step['event'];
    }
    if ($events) {
        $placeholders = implode(',', array_fill(0, count($events), '?'));
        $rows = allstat_fetch_all($pdo, "
            SELECT metric_date, event_name, SUM(event_count) AS total
            FROM events_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND event_name IN ($placeholders)
            GROUP BY metric_date, event_name
        ", array_merge([$domainId, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')], array_values($events)));

        $byEvent = [];
        foreach ($rows as $row) {
            $date = allstat_funnel_parse_date((string) $row['metric_date']);
            if (!$date) { continue; }
            $index = $position[$bucketOf($date)] ?? null;
            if ($index === null) { continue; }
            $event = strtolower((string) $row['event_name']);
            $byEvent[$event][$index] = ($byEvent[$event][$index] ?? 0) + (int) $row['total'];
        }
        foreach ($steps as $i => $step) {
            foreach ($byEvent[strtolower($step['event'])] ?? [] as $index => $total) {
                $series[$i][$index] = $total;
            }
        }
    }

    return ['granularity' => $granularity, 'labels' => $labels, 'series' => $series];
}

/**
 * Report trychtýře za období. $funnel = tvar z allstat_funnel_get; $breakdown = přepsání rozpadu (null = z trychtýře).
 * Tvar viz ['funnel', 'range', 'steps', 'overall', 'breakdown', 'trend', 'warnings'].
 */
function allstat_funnel_report(PDO $pdo, int $domainId, array $funnel, string $start, string $end, ?string $breakdown = null): array
{
    $startDate = allstat_funnel_parse_date($start);
    $endDate = allstat_funnel_parse_date($end);
    if (!$startDate || !$endDate) {
        // Neplatné období → posledních 28 dní do včerejška.
        $endDate = new DateTimeImmutable('yesterday');
        $startDate = $endDate->modify('-27 days');
    }
    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }
    $start = $startDate->format('Y-m-d');
    $end = $endDate->format('Y-m-d');

    $breakdowns = allstat_funnel_breakdowns();
    $breakdownKey = 'channel';
    foreach ([$breakdown, $funnel['breakdown'] ?? null] as $candidate) {
        if (is_string($candidate) && isset($breakdowns[$candidate])) {
            $breakdownKey = $candidate;
            break;
        }
    }

    $steps = [];
    foreach ($funnel['steps'] ?? [] as $step) {
        $event = trim((string) ($step['event'] ?? ''));
        if ($event === '') { continue; }
        $label = trim((string) ($step['label'] ?? ''));
        $steps[] = ['label' => $label !== '' ? $label : $event, 'event' => $event];
    }

    $events = [];
    foreach ($steps as $step) {
        $events[strtolower($step['event'])] = $step['event'];
    }
    $events = array_values($events);

    $totals = allstat_funnel_event_totals($pdo, $domainId, $events, $start, $end);
    $activity = allstat_funnel_step_activity($pdo, $domainId, $events, 28);
    $activityByLower = [];
    foreach ($activity as $event => $count) {
        $activityByLower[strtolower($event)] = $count;
    }

    $reportSteps = [];
    $warnings = [];
    $firstCount = 0;
    $previous = 0;
    foreach ($steps as $i => $step) {
        $count = (int) ($totals[strtolower($step['event'])] ?? 0);
        if ($i === 0) {
            $firstCount = $count;
        }
        $reportSteps[] = [
            'label' => $step['label'],
            'event' => $step['event'],
            'count' => $count,
            'fromPrev' => ($i > 0 && $previous > 0) ? $count / (float) $previous : null,
            'fromFirst' => $firstCount > 0 ? $count / (float) $firstCount : null,
            'dropOff' => $i > 0 ? max(0, $previous - $count) : 0,
        ];

        if (($activityByLower[strtolower($step['event'])] ?? 0) === 0) {
            $warnings[] = [
                'step' => $i,
                'event' => $step['event'],
                'message' => 'Event „' . $step['event'] . '“ za posledních 28 dní nepřišel, zkontrolujte měření (GTM).',
            ];
        } elseif ($count === 0) {
            $warnings[] = [
                'step' => $i,
                'event' => $step['event'],
                'message' => 'Event „' . $step['event'] . '“ ve zvoleném období nepřišel ani jednou (v posledních 28 dnech ano), takže tady trychtýř končí.',
            ];
        } elseif ($i > 0 && $previous > 0 && $count > $previous) {
            $warnings[] = [
                'step' => $i,
                'event' => $step['event'],
                'message' => 'Počet eventů „' . $step['event'] . '“ je vyšší než u předchozího kroku (počítají se události, ne unikátní návštěvníci).',
            ];
        }
        $previous = $count;
    }

    $lastCount = $reportSteps ? $reportSteps[count($reportSteps) - 1]['count'] : 0;

    return [
        'funnel' => [
            'id' => (int) ($funnel['id'] ?? 0),
            'name' => (string) ($funnel['name'] ?? ''),
            'breakdown' => $breakdownKey,
        ],
        'range' => ['start' => $start, 'end' => $end],
        'steps' => $reportSteps,
        'overall' => (count($reportSteps) >= 2 && $firstCount > 0) ? $lastCount / (float) $firstCount : null,
        'breakdown' => allstat_funnel_breakdown_report($pdo, $domainId, $steps, $breakdownKey, $start, $end),
        'trend' => allstat_funnel_trend_report($pdo, $domainId, $steps, $startDate, $endDate),
        'warnings' => $warnings,
    ];
}

/**
 * ČISTÁ funkce: odpověď GA4 runReport (dimenze date, eventName, sessionDefaultChannelGroup, sessionSource,
 * sessionMedium, sessionCampaignName; metriky eventCount, totalUsers) → seznam řádků pro events_source_daily.
 * Pořadí sloupců se čte z dimensionHeaders / metricHeaders, bez nich platí výchozí pořadí výše.
 * Duplicitní kombinace (date, event, source, medium, campaign bez ohledu na velikost písmen) se sloučí,
 * počty se sečtou a kanál si ponechá řádek s vyšším počtem eventů. Délky se ořežou podle sloupců.
 */
function allstat_funnel_parse_source_rows(array $report): array
{
    $dimensionNames = ['date', 'eventName', 'sessionDefaultChannelGroup', 'sessionSource', 'sessionMedium', 'sessionCampaignName'];
    $metricNames = ['eventCount', 'totalUsers'];

    $indexOf = static function (array $headers, array $names): array {
        $map = [];
        foreach ($headers as $position => $header) {
            $name = is_array($header) ? (string) ($header['name'] ?? '') : '';
            if ($name !== '' && !isset($map[$name])) {
                $map[$name] = $position;
            }
        }
        $result = [];
        foreach ($names as $position => $name) {
            $result[$name] = $map[$name] ?? $position;
        }

        return $result;
    };
    $dim = $indexOf((array) ($report['dimensionHeaders'] ?? []), $dimensionNames);
    $met = $indexOf((array) ($report['metricHeaders'] ?? []), $metricNames);

    $merged = [];
    foreach ((array) ($report['rows'] ?? []) as $row) {
        if (!is_array($row)) { continue; }
        $dimensions = (array) ($row['dimensionValues'] ?? []);
        $metrics = (array) ($row['metricValues'] ?? []);
        $value = static fn (array $values, int $index): string => trim((string) ($values[$index]['value'] ?? ''));

        $raw = $value($dimensions, $dim['date']);
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            continue;
        }
        $date = $m[1] . '-' . $m[2] . '-' . $m[3];

        $event = mb_substr($value($dimensions, $dim['eventName']), 0, 120);
        if ($event === '') { continue; }

        $item = [
            'date' => $date,
            'event' => $event,
            'channel' => mb_substr($value($dimensions, $dim['sessionDefaultChannelGroup']), 0, 80),
            'source' => mb_substr($value($dimensions, $dim['sessionSource']), 0, 190),
            'medium' => mb_substr($value($dimensions, $dim['sessionMedium']), 0, 120),
            'campaign' => mb_substr($value($dimensions, $dim['sessionCampaignName']), 0, 190),
            'event_count' => max(0, (int) round((float) $value($metrics, $met['eventCount']))),
            'total_users' => max(0, (int) round((float) $value($metrics, $met['totalUsers']))),
        ];

        $key = mb_strtolower(implode("\x1f", [$item['date'], $item['event'], $item['source'], $item['medium'], $item['campaign']]));
        if (!isset($merged[$key])) {
            $merged[$key] = $item;
            continue;
        }
        if ($item['event_count'] > $merged[$key]['event_count']) {
            $merged[$key]['channel'] = $item['channel'];
        }
        $merged[$key]['event_count'] += $item['event_count'];
        $merged[$key]['total_users'] += $item['total_users'];
    }

    return array_values($merged);
}

/**
 * Zapíše řádky z allstat_funnel_parse_source_rows do events_source_daily: v transakci smaže okno
 * (start až end) pro dané eventy webu a vloží nové řádky (kolize klíče se sečtou). Vrací počet vložených řádků.
 * Když už transakce běží (např. v testu), použije se ta stávající a nic se necommituje.
 */
function allstat_funnel_store_source_rows(PDO $pdo, int $domainId, string $start, string $end, array $events, array $rows): int
{
    $allowed = [];
    foreach ($events as $event) {
        $event = trim((string) $event);
        if ($event !== '') {
            $allowed[strtolower($event)] = $event;
        }
    }
    if (!$allowed) {
        return 0;
    }

    $rows = array_values(array_filter($rows, static fn (array $row): bool => isset($allowed[strtolower((string) ($row['event'] ?? ''))])));
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $pdo->prepare("
            DELETE FROM events_source_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND event_name IN ($placeholders)
        ")->execute(array_merge([$domainId, $start, $end], array_values($allowed)));

        foreach (array_chunk($rows, 200) as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                array_push(
                    $params,
                    $domainId, (string) $row['date'], (string) $row['event'], (string) ($row['channel'] ?? ''),
                    (string) ($row['source'] ?? ''), (string) ($row['medium'] ?? ''), (string) ($row['campaign'] ?? ''),
                    (int) ($row['event_count'] ?? 0), (int) ($row['total_users'] ?? 0)
                );
            }
            $pdo->prepare('
                INSERT INTO events_source_daily (domain_id, metric_date, event_name, channel, source, medium, campaign, event_count, total_users)
                VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?)')) . '
                ON DUPLICATE KEY UPDATE
                    event_count = event_count + VALUES(event_count),
                    total_users = total_users + VALUES(total_users),
                    channel = VALUES(channel)
            ')->execute($params);
        }

        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return count($rows);
}
