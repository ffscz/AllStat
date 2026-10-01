<?php

function allstat_db(array $config, bool $withDatabase = true): ?PDO
{
    static $connections = [];

    $db = $config['db'];
    $key = ($withDatabase ? 'db:' : 'server:') . implode('|', [
        $db['host'],
        (string) $db['port'],
        $db['database'],
        $db['username'],
        $db['charset'],
    ]);

    if (array_key_exists($key, $connections)) {
        return $connections[$key];
    }

    $databasePart = $withDatabase ? 'dbname=' . $db['database'] . ';' : '';
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . (int) $db['port'] . ';' . $databasePart . 'charset=' . $db['charset'];

    try {
        $connections[$key] = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable) {
        return null;
    }

    return $connections[$key];
}

function allstat_tables_ready(?PDO $pdo): bool
{
    if (!$pdo) {
        return false;
    }

    static $cache = [];
    $key = spl_object_id($pdo);

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $pdo->query('SELECT 1 FROM metrics_daily LIMIT 1');
        $cache[$key] = true;
    } catch (Throwable) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function allstat_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function allstat_fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch();

    return $row ?: null;
}
