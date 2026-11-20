<?php
/**
 * DMS - Distribution Management System
 * Database Transaction & Execution Helpers
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

/**
 * Execute a callable inside a managed database transaction
 *
 * @param callable $callback function(PDO $db)
 * @return mixed Result of the callback
 * @throws Throwable
 */
function db_transaction(callable $callback) {
    $pdo = db();
    $isAlreadyInTransaction = $pdo->inTransaction();

    if (!$isAlreadyInTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $result = $callback($pdo);
        if (!$isAlreadyInTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if (!$isAlreadyInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Fetch all matching records
 *
 * @param string $sql
 * @param array $params
 * @return array
 */
function db_fetch_all(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Fetch a single matching record
 *
 * @param string $sql
 * @param array $params
 * @return array|null
 */
function db_fetch_one(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $res = $stmt->fetch();
    return $res !== false ? $res : null;
}

/**
 * Fetch a single column value
 *
 * @param string $sql
 * @param array $params
 * @return mixed
 */
function db_fetch_val(string $sql, array $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $res = $stmt->fetchColumn();
    return $res !== false ? $res : null;
}

/**
 * Execute an INSERT / UPDATE / DELETE statement
 *
 * @param string $sql
 * @param array $params
 * @return int Number of affected rows
 */
function db_execute(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Get last insert ID
 *
 * @return int
 */
function db_last_insert_id(): int {
    return (int)db()->lastInsertId();
}
