<?php

require_once __DIR__ . '/providers.php';

function allstat_seed_providers(PDO $pdo): array
{
    $sourceStatement = $pdo->prepare('INSERT INTO data_sources (provider_key, name, category, supports_oauth, default_scopes, auth_url, token_url, api_base_url, docs_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), supports_oauth = VALUES(supports_oauth), default_scopes = VALUES(default_scopes), auth_url = VALUES(auth_url), token_url = VALUES(token_url), api_base_url = VALUES(api_base_url), docs_url = VALUES(docs_url)');
    $sources = allstat_default_providers();
    $sourceIds = [];

    foreach ($sources as $source) {
        $sourceStatement->execute([
            $source['provider_key'],
            $source['name'],
            $source['category'],
            $source['supports_oauth'],
            $source['default_scopes'],
            $source['auth_url'],
            $source['token_url'],
            $source['api_base_url'],
            $source['docs_url'],
        ]);
        $row = allstat_fetch_one($pdo, 'SELECT id FROM data_sources WHERE provider_key = ?', [$source['provider_key']]);
        $sourceIds[$source['provider_key']] = (int) $row['id'];
    }

    return $sourceIds;
}

function allstat_seed_demo(PDO $pdo): int
{
    $existing = (int) $pdo->query('SELECT COUNT(*) FROM domains')->fetchColumn();

    if ($existing > 0) {
        return 0;
    }

    $pdo->beginTransaction();

    try {
        $domainStatement = $pdo->prepare('INSERT INTO domains (name, url, is_active) VALUES (?, ?, 1)');
        $domains = allstat_demo_domains();
        $domainIds = [];

        foreach ($domains as $domain) {
            $domainStatement->execute([$domain['name'], $domain['url']]);
            $domainIds[(int) $domain['id']] = (int) $pdo->lastInsertId();
        }

        $sourceIds = allstat_seed_providers($pdo);
        $sources = allstat_default_providers();

        $domainSourceStatement = $pdo->prepare('
            INSERT INTO domain_sources (domain_id, source_id, account_label, scopes, auth_url, token_url, api_base_url, status, last_sync_at, token_expires_at, note, is_enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ');

        foreach ($domainIds as $originalDomainId => $databaseDomainId) {
            foreach ($sources as $source) {
                $status = $source['provider_key'] === 'linkedin_company' ? 'warning' : 'ok';
                $lastSync = $source['provider_key'] === 'linkedin_company' ? '2024-05-30 23:50:00' : '2024-05-31 02:15:00';
                $tokenExpires = $source['provider_key'] === 'linkedin_company' ? '2024-06-06 23:59:00' : null;
                $note = $source['provider_key'] === 'linkedin_company' ? 'token expires soon' : null;
                $domainSourceStatement->execute([
                    $databaseDomainId,
                    $sourceIds[$source['provider_key']],
                    $source['name'] . ' účet',
                    $source['default_scopes'],
                    $source['auth_url'],
                    $source['token_url'],
                    $source['api_base_url'],
                    $status,
                    $lastSync,
                    $tokenExpires,
                    $note,
                ]);
            }
        }

        $metricStatement = $pdo->prepare('
            INSERT INTO metrics_daily (domain_id, metric_date, visits, users_count, clicks, impressions, conversions)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $trafficStatement = $pdo->prepare('
            INSERT INTO traffic_sources_daily (domain_id, metric_date, source, sessions, conversions)
            VALUES (?, ?, ?, ?, ?)
        ');
        $landingStatement = $pdo->prepare('
            INSERT INTO landing_pages_daily (domain_id, metric_date, path, sessions, conversions)
            VALUES (?, ?, ?, ?, ?)
        ');
        $queryStatement = $pdo->prepare('
            INSERT INTO search_queries_daily (domain_id, metric_date, query_text, clicks, impressions, position)
            VALUES (?, ?, ?, ?, ?, ?)
        ');

        $landingSplits = [
            '/' => 0.184,
            '/sluzby/' => 0.089,
            '/blog/' => 0.076,
            '/kontakt/' => 0.051,
            '/o-nas/' => 0.045,
            '/cenik/' => 0.039,
        ];
        $querySplits = [
            ['vaše klíčové slovo', 0.131, 24.3, 2.8],
            ['další klíčový dotaz', 0.086, 19.1, 4.2],
            ['příklad dotazu', 0.059, 17.2, 5.7],
            ['seo optimalizace', 0.051, 19.6, 6.1],
            ['jak na seo', 0.04, 18.8, 7.4],
        ];

        foreach ($domainIds as $originalDomainId => $databaseDomainId) {
            $rows = allstat_demo_metric_rows((int) $originalDomainId, '2024-04-01', '2024-05-31');

            foreach ($rows as $row) {
                $metricStatement->execute([
                    $databaseDomainId,
                    $row['date'],
                    $row['visits'],
                    $row['users'],
                    $row['clicks'],
                    $row['impressions'],
                    $row['conversions'],
                ]);

                foreach (allstat_demo_sources(allstat_summary_from_values($row)) as $source) {
                    $trafficStatement->execute([
                        $databaseDomainId,
                        $row['date'],
                        $source['source'],
                        $source['sessions'],
                        $source['conversions'],
                    ]);
                }

                foreach ($landingSplits as $path => $share) {
                    $sessions = (int) round($row['visits'] * $share);
                    $landingStatement->execute([$databaseDomainId, $row['date'], $path, $sessions, (int) round($sessions * 0.024)]);
                }

                foreach ($querySplits as $query) {
                    $clicks = max(1, (int) round($row['clicks'] * $query[1]));
                    $impressions = max($clicks, (int) round($clicks / ($query[2] / 100)));
                    $queryStatement->execute([$databaseDomainId, $row['date'], $query[0], $clicks, $impressions, $query[3]]);
                }
            }
        }

        $pdo->commit();

        return count($domainIds);
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
