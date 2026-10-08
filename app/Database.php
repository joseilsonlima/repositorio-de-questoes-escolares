<?php
declare(strict_types=1);
namespace School;
final class Database {
    public readonly \PDO $pdo;
    public readonly bool $sqlite;
    public function __construct(?\PDO $pdo = null) {
        $this->pdo = $pdo ?? new \PDO(getenv('DB_DSN') ?: '', getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false]);
        $this->sqlite = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($this->sqlite) { $this->pdo->exec('PRAGMA foreign_keys = ON'); $this->pdo->exec('PRAGMA busy_timeout = 5000'); }
    }
    public function execute(string $sql, array $params = []): \PDOStatement {
        $s = $this->pdo->prepare($sql); $s->execute($params); return $s;
    }
    public function one(string $sql, array $params = []): ?array { return $this->execute($sql, $params)->fetch() ?: null; }
    public function all(string $sql, array $params = []): array { return $this->execute($sql, $params)->fetchAll(); }
    public function insert(string $table, array $fields): int {
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', array_keys($fields)) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')', array_values($fields));
        return (int)$this->pdo->lastInsertId();
    }
    public function transaction(callable $action): mixed {
        if ($this->sqlite) $this->pdo->exec('BEGIN IMMEDIATE'); else $this->pdo->beginTransaction();
        try { $r = $action(); if ($this->sqlite) $this->pdo->exec('COMMIT'); else $this->pdo->commit(); return $r; }
        catch (\Throwable $e) { if ($this->sqlite) $this->pdo->exec('ROLLBACK'); elseif ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    public function lock(string $table, int $id): ?array { return $this->one("SELECT * FROM $table WHERE id = ?" . ($this->sqlite ? '' : ' FOR UPDATE'), [$id]); }
    public function audit(?int $actor, string $action, string $entity, int $id, array $details = []): void {
        $this->insert('audit_log', ['actor_id'=>$actor,'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'details'=>json_encode($details, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'created_at'=>gmdate('Y-m-d H:i:s')]);
    }
}
