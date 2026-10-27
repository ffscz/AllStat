<?php
/**
 * Skládání opakovaných kusů markupu detailních pohledů (přehled GA4 + GSC a detaily zdrojů) z komponent
 * v assets/css/components.css: záhlaví karty, měřáky, složený pruh, řádky „popis – hodnota – změna“.
 * Stejný markup kreslí po AJAX obnově assets/js/app.js (meterHtml, stackHtml), při změně upravit obojí.
 */
if (function_exists('allstat_ui_card_head')) {
    return;
}

/** Záhlaví karty: nadpis (s malým zdrojem), popis (HTML) a volitelně obsah vpravo (počet, tlačítko). */
function allstat_ui_card_head(string $title, string $small = '', string $descHtml = '', string $asideHtml = ''): string
{
    return '<div class="card-head"><div><h2 class="card-title">' . h($title)
        . ($small !== '' ? '<small>' . h($small) . '</small>' : '') . '</h2>'
        . ($descHtml !== '' ? '<p class="card-desc">' . $descHtml . '</p>' : '') . '</div>'
        . $asideHtml . '</div>';
}

/** Počet vpravo v záhlaví karty („762 celkem“). */
function allstat_ui_card_total(string $text, string $attr = ''): string
{
    return '<span class="metric-note num"' . ($attr !== '' ? ' ' . $attr : '') . '>' . h($text) . '</span>';
}

/**
 * Třída barvy změny: růst zeleně, pokles červeně; u metrik, kde je lepší nižší hodnota (míra odchodů,
 * CPC …), naopak. Bez srovnání tlumeně.
 */
function allstat_ui_delta_class(?float $change, bool $higherIsBetter = true): string
{
    if ($change === null) {
        return 'is-muted';
    }
    if (abs($change) < 0.05) {
        return 'is-flat';
    }

    return ($change > 0) === $higherIsBetter ? 'is-up' : 'is-down';
}

/**
 * Seznam měřáků (popis, hodnota, podíl a pruh). Řádek: label, value, share?, width (0–100), title?,
 * extraHtml? (za popisem, např. štítek), noteHtml? (pod pruhem).
 */
function allstat_ui_meters(array $rows, string $attr = '', string $emptyText = ''): string
{
    $html = '<div class="meters"' . ($attr !== '' ? ' ' . $attr : '') . '>';
    foreach ($rows as $row) {
        $html .= allstat_ui_meter($row);
    }
    if (!$rows && $emptyText !== '') {
        $html .= '<p class="card-desc">' . h($emptyText) . '</p>';
    }

    return $html . '</div>';
}

function allstat_ui_meter(array $row): string
{
    $width = max(0.0, min(100.0, (float) ($row['width'] ?? 0)));
    $share = (string) ($row['share'] ?? '');

    return '<div class="meter"><div class="meter-top">'
        . '<span class="meter-name ellipsis" title="' . h($row['title'] ?? $row['label']) . '">' . h($row['label']) . ($row['extraHtml'] ?? '') . '</span>'
        . '<span class="row-end"><span class="row-value num">' . h($row['value']) . '</span>'
        . ($share !== '' ? '<span class="row-share num">' . h($share) . '</span>' : '') . '</span></div>'
        . '<div class="track"><div class="track-fill" style="width: ' . round(max($width, $width > 0 ? 1 : 0), 1) . '%"></div></div>'
        . (!empty($row['noteHtml']) ? '<span class="block-desc">' . $row['noteHtml'] . '</span>' : '')
        . '</div>';
}

/** Šířky pruhů měřáků poměrem k největší položce (nejlepší čitelnost rozdílů). */
function allstat_ui_widths(array $values): array
{
    $max = max(1.0, ...array_map('floatval', $values ?: [0]));

    return array_map(static fn ($v): float => (float) $v / $max * 100, $values);
}

/**
 * Složený pruh s legendou (zařízení, noví a vracející se, pohlaví). Segment: label, value, share
 * (popisek), pct (číslo 0–100). $attr pro AJAX obnovu.
 */
function allstat_ui_stack(string $title, string $totalLabel, array $segments, string $attr = '', string $emptyText = ''): string
{
    $html = '<div class="meter"' . ($attr !== '' ? ' ' . $attr : '') . '><div class="meter-top"><span class="block-title">' . h($title) . '</span>'
        . ($totalLabel !== '' ? '<span class="block-desc num" data-stack-total>' . h($totalLabel) . '</span>' : '') . '</div>';
    $html .= allstat_ui_stack_body($segments, $emptyText);

    return $html . '</div>';
}

function allstat_ui_stack_body(array $segments, string $emptyText = ''): string
{
    if (!$segments) {
        return '<p class="card-desc">' . h($emptyText) . '</p>';
    }
    $aria = implode(', ', array_map(static fn (array $s): string => $s['label'] . ' ' . $s['share'], $segments));
    $bar = '<div class="stack" role="img" aria-label="' . h($aria) . '">';
    $legend = '<div class="rows rows-compact">';
    foreach ($segments as $i => $s) {
        $fill = 'fill-' . min(3, $i + 1);
        $bar .= '<span class="' . $fill . '" style="width: ' . round(max(0.0, (float) $s['pct']), 1) . '%"></span>';
        $legend .= '<div class="row"><span class="swatch ' . $fill . '"></span><span class="row-label">' . h($s['label']) . '</span>'
            . '<span class="row-end"><span class="row-value num">' . h($s['value']) . '</span>'
            . (($s['share'] ?? '') !== '' && $s['share'] !== $s['value'] ? '<span class="row-share num">' . h($s['share']) . '</span>' : '') . '</span></div>';
    }

    return $bar . '</div>' . $legend . '</div>';
}

/**
 * Řádky „popis – hodnota – změna“. Řádek: label, value, delta? (text), deltaClass?, title?, attr? (data-*
 * pro AJAX obnovu), valueAttr?, deltaAttr?
 */
function allstat_ui_rows(array $rows): string
{
    $html = '<div class="rows">';
    foreach ($rows as $row) {
        $hasDelta = array_key_exists('delta', $row);
        $html .= '<div class="row"' . (!empty($row['attr']) ? ' ' . $row['attr'] : '') . (!empty($row['title']) ? ' title="' . h($row['title']) . '"' : '') . '>'
            . '<span class="row-label">' . h($row['label']) . '</span><span class="row-end">'
            . '<span class="row-value num"' . (!empty($row['valueAttr']) ? ' ' . $row['valueAttr'] : '') . '>' . ($row['valueHtml'] ?? h($row['value'])) . '</span>'
            . ($hasDelta ? '<span class="row-delta num ' . h($row['deltaClass'] ?? 'is-muted') . '"' . (!empty($row['deltaAttr']) ? ' ' . $row['deltaAttr'] : '') . '>' . h((string) $row['delta']) . '</span>' : '')
            . '</span></div>';
    }

    return $html . '</div>';
}

/**
 * Popisek období: celý kalendářní měsíc jako „září 2026“, jinak „1. 9. – 15. 9. 2026“.
 * Stejnou logiku má periodLabel() v assets/js/app.js.
 */
function allstat_ui_period_label(string $start, string $end): string
{
    $s = new DateTimeImmutable($start);
    $e = new DateTimeImmutable($end);
    if ($s->format('d') === '01' && $e->format('Y-m-d') === $s->format('Y-m-t')) {
        return allstat_month_names()[(int) $s->format('n') - 1] . ' ' . $s->format('Y');
    }
    if ($start === $end) {
        return allstat_date_label($start);
    }
    $short = allstat_locale() === 'en' ? 'j M' : 'j. n.';

    return ($s->format('Y') === $e->format('Y') ? $s->format($short) : allstat_date_label($start)) . ' – ' . allstat_date_label($end);
}

/** Záložka metriky nad grafem (přehled i detail zdroje); klik přepne řadu v grafu (assets/js/app.js). */
function allstat_ui_metric_tab(string $key, string $label, string $value, string $noteHtml, bool $selected, string $tooltip = ''): string
{
    return '<button type="button" class="tab" role="tab" aria-selected="' . ($selected ? 'true' : 'false') . '" tabindex="' . ($selected ? '0' : '-1') . '"'
        . ' aria-controls="metricChartPanel" data-metric-tab="' . h($key) . '" data-kpi-card="' . h($key) . '"'
        . ($tooltip !== '' ? ' title="' . h($tooltip) . '"' : '') . '>'
        . '<span class="metric-label" data-metric-label>' . h($label) . '</span>'
        . '<span class="metric-value num" data-kpi-value>' . h($value) . '</span>'
        . ($noteHtml !== '' ? '<span class="metric-note">' . $noteHtml . '</span>' : '')
        . '</button>';
}

/** Graf pod záložkami: nadpis (doplní JS podle metriky a intervalu), legenda s obdobím a canvas. */
function allstat_ui_metric_chart(string $canvasId, string $periodLabel): string
{
    return '<div class="chart-wrap" id="metricChartPanel" role="tabpanel">'
        . '<div class="chart-head"><h2 class="chart-title" data-chart-title></h2>'
        . '<div class="legend"><span class="legend-item"><span class="swatch-line"></span><span data-period-label>' . h(mb_strtoupper(mb_substr($periodLabel, 0, 1)) . mb_substr($periodLabel, 1)) . '</span></span></div></div>'
        . '<div class="chart"><canvas id="' . h($canvasId) . '"></canvas></div></div>';
}

/** Změna z allstat_social_delta() pro řádek allstat_ui_rows(): text a barva. */
function allstat_ui_social_delta(?array $delta, bool $higherIsBetter = true): array
{
    if (!$delta || ($delta['trend'] ?? 'none') === 'none') {
        return ['delta' => '', 'deltaClass' => 'is-muted'];
    }

    return ['delta' => (string) $delta['label'], 'deltaClass' => allstat_ui_delta_class($delta['change'] === null ? null : (float) $delta['change'], $higherIsBetter)];
}

/** Číslo se znaménkem (+83, −4, 0) pro přírůstky. */
function allstat_ui_signed(int|float $value, int $decimals = 0): string
{
    return ($value > 0 ? '+' : ($value < 0 ? '−' : '')) . allstat_number(abs($value), $decimals);
}
