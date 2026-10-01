<?php

/**
 * AI assistant referral classification.
 *
 * GA4 reports the referrer host in the `sessionSource` dimension. AI chat tools
 * (ChatGPT, Perplexity, Gemini…) pass their host as the referrer, so we can detect
 * AI-driven traffic by matching that host against a known list. Sessions whose source
 * doesn't map to any AI tool return null and are ignored.
 *
 * Used by the GA4 sync (lib/sync.php) to write into ai_sources_daily and by the
 * GA4 server-side regexp pre-filter to keep the fetched row set small.
 */

/**
 * Canonical AI tool label => list of lowercase needles matched against the raw GA4 source.
 * Order matters: the first matching label wins.
 */
function allstat_ai_source_map(): array
{
    return [
        'ChatGPT' => ['chatgpt', 'openai'],
        'Perplexity' => ['perplexity'],
        'Gemini' => ['gemini', 'bard.google'],
        'Copilot' => ['copilot', 'edgeservices.bing'],
        'Claude' => ['claude'],
        'Grok' => ['grok', 'x.ai'],
        'DeepSeek' => ['deepseek'],
        'Mistral' => ['mistral', 'lechat'],
        'Meta AI' => ['meta.ai'],
        'You.com' => ['you.com'],
        'Poe' => ['poe.com'],
        'Phind' => ['phind'],
    ];
}

/**
 * Map a raw GA4 sessionSource value to a canonical AI tool label, or null when it is not AI.
 */
function allstat_classify_ai_source(string $rawSource): ?string
{
    $source = mb_strtolower(trim($rawSource));
    if ($source === '') {
        return null;
    }

    foreach (allstat_ai_source_map() as $label => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($source, $needle)) {
                return $label;
            }
        }
    }

    return null;
}

/**
 * RE2 alternation of every AI needle, for the GA4 PARTIAL_REGEXP dimension filter.
 * Superset of what allstat_classify_ai_source() matches, so the PHP classifier stays authoritative.
 */
function allstat_ai_source_regexp(): string
{
    $needles = [];
    foreach (allstat_ai_source_map() as $list) {
        foreach ($list as $needle) {
            $needles[] = preg_quote($needle, '/');
        }
    }

    return implode('|', $needles);
}
