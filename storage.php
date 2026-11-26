<?php
declare(strict_types=1);

if (!defined('AI_PRACTICE_BOOT')) { http_response_code(404); exit; }

function dataDir(): string {
    $configured = getenv('AI_PRACTICE_DATA_DIR');
    if ($configured !== false && $configured !== '') {
        if ($configured[0] !== '/') throw new RuntimeException('AI_PRACTICE_DATA_DIR must be absolute');
        return rtrim($configured, '/');
    }
    return __DIR__ . '/../private'; // 兼容早期 1Panel 安装。
}

function db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $db = new PDO('sqlite:' . dataDir() . '/practice.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000; PRAGMA foreign_keys = ON;');
    $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($version < 4) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
            if ($version === 0) {
                $db->exec('CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                    password_hash TEXT NOT NULL, created_at INTEGER NOT NULL
                ); CREATE TABLE IF NOT EXISTS attempts (
                    id TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id), finished INTEGER NOT NULL,
                    right_n INTEGER NOT NULL, wrong_n INTEGER NOT NULL, miss_n INTEGER NOT NULL, total INTEGER NOT NULL,
                    paper TEXT NOT NULL, answers TEXT NOT NULL
                ); CREATE INDEX IF NOT EXISTS attempts_user_time ON attempts(user_id, finished DESC, id DESC);
                CREATE TABLE IF NOT EXISTS auth_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, until_ts INTEGER NOT NULL);');
            }
            if ($version < 2) $db->exec("ALTER TABLE attempts ADD COLUMN mode TEXT NOT NULL DEFAULT 'exam'");
            if ($version < 3) $db->exec('CREATE TABLE IF NOT EXISTS practice_drafts (
                user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                paper_id TEXT NOT NULL, paper TEXT NOT NULL, answers TEXT NOT NULL,
                position INTEGER NOT NULL, answered INTEGER NOT NULL, total INTEGER NOT NULL
            )');
            if ($version < 4) $db->exec('ALTER TABLE users ADD COLUMN banned INTEGER NOT NULL DEFAULT 0;
                ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 0;
                CREATE TABLE admin_log (
                    id INTEGER PRIMARY KEY, created_at INTEGER NOT NULL, action TEXT NOT NULL,
                    target TEXT NOT NULL, detail TEXT NOT NULL
                ); CREATE TABLE admin_account (
                    id INTEGER PRIMARY KEY CHECK (id=1), password_hash TEXT NOT NULL, temporary INTEGER NOT NULL
                ); PRAGMA user_version = 4;');
            $db->exec('COMMIT');
        } catch (Throwable $err) {
            $db->exec('ROLLBACK'); throw $err;
        }
    }
    return $db;
}
function query(string $sql, array $params = []): PDOStatement {
    $q = db()->prepare($sql); $q->execute($params); return $q;
}
