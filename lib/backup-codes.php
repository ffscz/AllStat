<?php

/**
 * Záložní kódy pro 2FA: 10 jednorázových kódů, kterými se dá přihlásit místo kódu z aplikace (ztracený telefon).
 * Ukážou se jen jednou při vygenerování, v databázi je jen jejich bcrypt hash (tabulka allstat_totp_backup_codes).
 * Bcrypt místo HMAC záměrně: nezávisí na šifrovacím klíči, takže kódy přežijí jeho rotaci (admin/security.php).
 * Chybný záložní kód se počítá do stejného limitu jako chybný kód z aplikace (login_2fa_failed v auditu).
 */

const ALLSTAT_BACKUP_CODE_COUNT = 10;
/** Při tolika a méně zbývajících kódech stránka 2FA upozorní, ať si uživatel vygeneruje nové. */
const ALLSTAT_BACKUP_CODE_LOW = 2;
/** Bez 0/O a 1/I/L, aby se kód z papíru nedal přečíst špatně. */
const ALLSTAT_BACKUP_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

/** Kód pro zobrazení: 8 znaků ve dvou skupinách (7KQ4-M9XD). */
function allstat_backup_code_generate(): string
{
    $alphabet = ALLSTAT_BACKUP_CODE_ALPHABET;
    $code = '';

    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return substr($code, 0, 4) . '-' . substr($code, 4);
}

/** Tvar pro porovnání: velká písmena bez mezer a pomlček; jiná délka nebo znaky = prázdný řetězec. */
function allstat_backup_code_normalize(string $code): string
{
    $code = strtoupper((string) preg_replace('/[\s-]+/', '', $code));

    return preg_match('/^[' . ALLSTAT_BACKUP_CODE_ALPHABET . ']{8}$/', $code) === 1 ? $code : '';
}

/** Počet nepoužitých kódů uživatele; před migrací (tabulka ještě není) 0. */
function allstat_backup_codes_remaining(PDO $pdo, int $userId): int
{
    try {
        $row = allstat_fetch_one($pdo, 'SELECT COUNT(*) AS remaining FROM allstat_totp_backup_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    } catch (Throwable) {
        return 0;
    }

    return (int) ($row['remaining'] ?? 0);
}

/**
 * Smaže dosavadní kódy uživatele a vytvoří nové. Vrací kódy v čitelné podobě (jen pro jedno zobrazení),
 * nebo null, když se je nepodařilo uložit.
 *
 * @return list<string>|null
 */
function allstat_backup_codes_regenerate(PDO $pdo, int $userId, ?int $actorId = null): ?array
{
    $codes = [];
    while (count($codes) < ALLSTAT_BACKUP_CODE_COUNT) {
        $codes[allstat_backup_code_generate()] = true;
    }
    $codes = array_keys($codes);

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM allstat_totp_backup_codes WHERE user_id = ?')->execute([$userId]);
        $insert = $pdo->prepare('INSERT INTO allstat_totp_backup_codes (user_id, code_hash) VALUES (?, ?)');
        foreach ($codes as $code) {
            $insert->execute([$userId, password_hash(allstat_backup_code_normalize($code), PASSWORD_DEFAULT)]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('AllStat: záložní kódy 2FA se nepodařilo uložit: ' . $exception->getMessage());

        return null;
    }

    allstat_audit($pdo, $actorId ?? $userId, $userId, 'backup_codes_generated');

    return $codes;
}

/** Smaže všechny kódy uživatele (reset nebo vypnutí 2FA, smazání účtu). */
function allstat_backup_codes_delete(PDO $pdo, int $userId): void
{
    try {
        $pdo->prepare('DELETE FROM allstat_totp_backup_codes WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $exception) {
        error_log('AllStat: záložní kódy 2FA se nepodařilo smazat: ' . $exception->getMessage());
    }
}

/**
 * Ověří záložní kód a při shodě ho spotřebuje (used_at). Spotřeba je podmíněná `used_at IS NULL`, takže dva
 * souběžné požadavky se stejným kódem neprojdou oba.
 */
function allstat_backup_code_consume(PDO $pdo, int $userId, string $code): bool
{
    $code = allstat_backup_code_normalize($code);

    try {
        $statement = $pdo->prepare('SELECT id, code_hash FROM allstat_totp_backup_codes WHERE user_id = ? AND used_at IS NULL');
        $statement->execute([$userId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $rows = [];
    }

    foreach ($code === '' ? [] : $rows as $row) {
        if (password_verify($code, (string) $row['code_hash'])) {
            $update = $pdo->prepare('UPDATE allstat_totp_backup_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
            $update->execute([(int) $row['id']]);

            return $update->rowCount() === 1;
        }
    }

    // Neúspěch trvá vždy zhruba stejně (10 porovnání), ať účet kódy má, nemá, nebo kód nemá správný tvar:
    // z doby odpovědi nesmí jít poznat, kolik kódů zbývá.
    static $dummy = null;
    $dummy ??= password_hash('allstat-backup-dummy', PASSWORD_DEFAULT);
    for ($i = $code === '' ? 0 : count($rows); $i < ALLSTAT_BACKUP_CODE_COUNT; $i++) {
        password_verify('x', $dummy);
    }

    return false;
}
