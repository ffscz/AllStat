<?php

function allstat_validate_password(string $password): ?string
{
    if (strlen($password) < 12) {
        return 'Heslo musí mít alespoň 12 znaků.';
    }

    if (strlen($password) > 200) {
        return 'Heslo je příliš dlouhé.';
    }

    return null;
}

function allstat_list_users(PDO $pdo): array
{
    return allstat_fetch_all($pdo, 'SELECT u.id, u.email, u.name, u.role, u.domain_access, u.is_active, u.totp_secret_enc, u.last_login_at, u.created_at,
            (SELECT COUNT(*) FROM allstat_user_domains ud JOIN domains d ON d.id = ud.domain_id WHERE ud.user_id = u.id AND d.is_active = 1) AS domain_count
        FROM allstat_users u ORDER BY u.name ASC, u.email ASC');
}

/**
 * Id webů přidělených uživateli (bez ohledu na to, jestli má přístup „Všechny weby").
 *
 * @return list<int>
 */
function allstat_user_assigned_domain_ids(PDO $pdo, int $userId): array
{
    $rows = allstat_fetch_all($pdo, 'SELECT domain_id FROM allstat_user_domains WHERE user_id = ? ORDER BY domain_id', [$userId]);

    return array_map(static fn (array $row): int => (int) $row['domain_id'], $rows);
}

/**
 * Uloží přístup k webům z formuláře: domain_access ('all' | 'selected') + domain_ids[]. Neexistující id se zahodí.
 * Výběr se ukládá i u administrátora a u „Všech webů", aby platil, kdyby se přístup později zúžil.
 */
function allstat_save_user_domains(PDO $pdo, ?int $actorId, int $userId, array $data): void
{
    $access = ($data['domain_access'] ?? 'all') === 'selected' ? 'selected' : 'all';
    $requested = array_values(array_unique(array_filter(array_map('intval', (array) ($data['domain_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
    $ids = $requested ? array_map('intval', array_column(allstat_fetch_all(
        $pdo,
        'SELECT id FROM domains WHERE id IN (' . implode(',', array_fill(0, count($requested), '?')) . ') ORDER BY id',
        $requested
    ), 'id')) : [];

    $before = allstat_fetch_one($pdo, 'SELECT domain_access FROM allstat_users WHERE id = ?', [$userId]);
    $beforeIds = allstat_user_assigned_domain_ids($pdo, $userId);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE allstat_users SET domain_access = ? WHERE id = ?')->execute([$access, $userId]);
        $pdo->prepare('DELETE FROM allstat_user_domains WHERE user_id = ?')->execute([$userId]);
        $insert = $pdo->prepare('INSERT INTO allstat_user_domains (user_id, domain_id) VALUES (?, ?)');
        foreach ($ids as $domainId) {
            $insert->execute([$userId, $domainId]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    if (($before['domain_access'] ?? 'all') !== $access || $beforeIds !== $ids) {
        allstat_audit($pdo, $actorId, $userId, 'user_domains', $access === 'all' ? 'všechny weby' : 'vybrané weby: ' . ($ids ? implode(', ', $ids) : 'žádný'));
    }
}

function allstat_get_user(PDO $pdo, int $id): ?array
{
    return allstat_fetch_one($pdo, 'SELECT * FROM allstat_users WHERE id = ?', [$id]);
}

function allstat_create_user(PDO $pdo, ?int $actorId, array $data): array
{
    $email = allstat_normalize_email((string) ($data['email'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $role = in_array(($data['role'] ?? 'user'), ['admin', 'user'], true) ? $data['role'] : 'user';
    $password = (string) ($data['password'] ?? '');
    $passwordError = allstat_validate_password($password);

    if (!allstat_is_valid_email($email)) {
        return ['ok' => false, 'message' => 'Zadejte platný e-mail.'];
    }

    if ($name === '') {
        return ['ok' => false, 'message' => 'Zadejte jméno uživatele.'];
    }

    if ($passwordError) {
        return ['ok' => false, 'message' => $passwordError];
    }

    try {
        $statement = $pdo->prepare('INSERT INTO allstat_users (email, name, role, password_hash, is_active) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$email, $name, $role, password_hash($password, PASSWORD_DEFAULT), !empty($data['is_active']) ? 1 : 0]);
        $userId = (int) $pdo->lastInsertId();
        allstat_audit($pdo, $actorId, $userId, 'user_created', $email);
        if (array_key_exists('domain_access', $data)) {
            allstat_save_user_domains($pdo, $actorId, $userId, $data);
        }

        return ['ok' => true, 'id' => $userId, 'message' => 'Uživatel byl vytvořen.'];
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => str_contains($exception->getMessage(), 'Duplicate') ? 'Uživatel s tímto e-mailem už existuje.' : 'Uživatele se nepodařilo vytvořit.'];
    }
}

function allstat_update_user(PDO $pdo, ?int $actorId, int $id, array $data): array
{
    $user = allstat_get_user($pdo, $id);

    if (!$user) {
        return ['ok' => false, 'message' => 'Uživatel neexistuje.'];
    }

    $email = allstat_normalize_email((string) ($data['email'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $role = in_array(($data['role'] ?? 'user'), ['admin', 'user'], true) ? $data['role'] : 'user';

    if (!allstat_is_valid_email($email)) {
        return ['ok' => false, 'message' => 'Zadejte platný e-mail.'];
    }

    if ($name === '') {
        return ['ok' => false, 'message' => 'Zadejte jméno uživatele.'];
    }

    $statement = $pdo->prepare('UPDATE allstat_users SET email = ?, name = ?, role = ?, is_active = ? WHERE id = ?');
    $statement->execute([$email, $name, $role, !empty($data['is_active']) ? 1 : 0, $id]);
    if (array_key_exists('domain_access', $data)) {
        allstat_save_user_domains($pdo, $actorId, $id, $data);
    }

    $password = (string) ($data['password'] ?? '');
    if ($password !== '') {
        $passwordError = allstat_validate_password($password);

        if ($passwordError) {
            return ['ok' => false, 'message' => $passwordError];
        }

        $statement = $pdo->prepare('UPDATE allstat_users SET password_hash = ? WHERE id = ?');
        $statement->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    allstat_audit($pdo, $actorId, $id, 'user_updated', $email);

    return ['ok' => true, 'message' => 'Uživatel byl uložen.'];
}

function allstat_reset_user_totp(PDO $pdo, ?int $actorId, int $id): array
{
    $statement = $pdo->prepare('UPDATE allstat_users SET totp_secret_enc = NULL, totp_last_window = NULL WHERE id = ?');
    $statement->execute([$id]);
    allstat_audit($pdo, $actorId, $id, 'user_2fa_reset');

    return ['ok' => true, 'message' => '2FA bylo uživateli resetováno.'];
}

function allstat_setup_user_totp(PDO $pdo, array $config, int $id, string $secret, string $code): array
{
    $matchedWindow = null;

    if (!allstat_totp_verify($secret, $code, -1, $matchedWindow)) {
        return ['ok' => false, 'message' => 'Ověřovací kód nesedí.'];
    }

    $statement = $pdo->prepare('UPDATE allstat_users SET totp_secret_enc = ?, totp_last_window = ? WHERE id = ?');
    $statement->execute([allstat_encrypt_secret($secret, $config), $matchedWindow, $id]);
    $_SESSION['totp_verified'] = true;
    $_SESSION['totp_action_verified_at'] = time();
    allstat_audit($pdo, $id, $id, 'totp_setup');

    return ['ok' => true, 'message' => '2FA je zapnuté.'];
}

function allstat_delete_user(PDO $pdo, ?int $actorId, int $id): array
{
    if ($actorId === $id) {
        return ['ok' => false, 'message' => 'Vlastní účet nejde smazat.'];
    }

    $statement = $pdo->prepare('DELETE FROM allstat_users WHERE id = ?');
    $statement->execute([$id]);
    $pdo->prepare('DELETE FROM allstat_user_domains WHERE user_id = ?')->execute([$id]);
    allstat_audit($pdo, $actorId, $id, 'user_deleted');

    return ['ok' => true, 'message' => 'Uživatel byl odstraněn.'];
}
