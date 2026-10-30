<?php

declare(strict_types=1);

namespace Dzentota\Session\Storage;

use Dzentota\Session\Value\SessionId;
use PDO;

/**
 * Database storage adapter for session data with encryption support
 */
class DatabaseStorage implements SessionStorageInterface
{
    /**
     * @param PDO $pdo PDO connection instance
     * @param string $table Table name for session storage
     * @param callable|null $encryptor Optional callback for encrypting data (function(string $data): string)
     * @param callable|null $decryptor Optional callback for decrypting data (function(string $encryptedData): string)
     */
    public function __construct(
        private PDO $pdo,
        private string $table = 'sessions',
        private mixed $encryptor = null,
        private mixed $decryptor = null
    ) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->table)) {
            throw new \InvalidArgumentException(
                "Invalid table name \"{$this->table}\": only letters, digits, and underscores are allowed."
            );
        }

        if ($this->encryptor !== null && !is_callable($this->encryptor)) {
            throw new \InvalidArgumentException('The encryptor must be callable.');
        }

        if ($this->decryptor !== null && !is_callable($this->decryptor)) {
            throw new \InvalidArgumentException('The decryptor must be callable.');
        }

        // Note: the caller's PDO error mode is NOT changed permanently. Each
        // operation switches to ERRMODE_EXCEPTION and restores the previous mode.
    }

    private ?\Throwable $lastError = null;

    /**
     * Run $fn with PDO::ERRMODE_EXCEPTION, restoring the caller's error mode afterwards.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function guarded(callable $fn): mixed
    {
        $previous = $this->pdo->getAttribute(PDO::ATTR_ERRMODE);
        if ($previous === PDO::ERRMODE_EXCEPTION) {
            return $fn();
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            return $fn();
        } finally {
            if ($previous !== null) {
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, $previous);
            }
        }
    }

    /**
     * The exception from the last failed createTable() call, or null.
     */
    public function getLastError(): ?\Throwable
    {
        return $this->lastError;
    }

    /**
     * {@inheritDoc}
     */
    public function read(SessionId $sessionId): ?string
    {
        return $this->guarded(function () use ($sessionId) {
            $stmt = $this->pdo->prepare(
                "SELECT session_data FROM {$this->table} WHERE session_id = :id AND expires_at > :now"
            );

            $stmt->bindValue(':id', $sessionId->toNative(), PDO::PARAM_STR);
            $stmt->bindValue(':now', time(), PDO::PARAM_INT);
            $stmt->execute();

            $data = $stmt->fetchColumn();
            if ($data === false) {
                return null;
            }

            // Decrypt data if a decryptor is provided
            if ($this->decryptor !== null) {
                return ($this->decryptor)($data);
            }

            return $data;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function write(SessionId $sessionId, string $data, int $lifetime): bool
    {
        return $this->guarded(function () use ($sessionId, $data, $lifetime) {
            // Encrypt data if an encryptor is provided
            $dataToStore = ($this->encryptor !== null) ? ($this->encryptor)($data) : $data;

            $expiresAt = time() + $lifetime;

            // Try to update existing record first
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->table} SET session_data = :data, expires_at = :expires 
                 WHERE session_id = :id"
            );

            $stmt->bindValue(':id', $sessionId->toNative(), PDO::PARAM_STR);
            $stmt->bindValue(':data', $dataToStore, PDO::PARAM_LOB);
            $stmt->bindValue(':expires', $expiresAt, PDO::PARAM_INT);
            $stmt->execute();

            // If no rows were affected, insert a new record
            if ($stmt->rowCount() === 0) {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO {$this->table} (session_id, session_data, expires_at, created_at) 
                     VALUES (:id, :data, :expires, :created)"
                );

                $stmt->bindValue(':id', $sessionId->toNative(), PDO::PARAM_STR);
                $stmt->bindValue(':data', $dataToStore, PDO::PARAM_LOB);
                $stmt->bindValue(':expires', $expiresAt, PDO::PARAM_INT);
                $stmt->bindValue(':created', time(), PDO::PARAM_INT);
                $stmt->execute();
            }

            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(SessionId $sessionId): bool
    {
        return $this->guarded(function () use ($sessionId) {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE session_id = :id");
            $stmt->bindValue(':id', $sessionId->toNative(), PDO::PARAM_STR);
            $stmt->execute();

            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function gc(int $maxLifetime): bool
    {
        return $this->guarded(function () {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires_at < :time");
            $stmt->bindValue(':time', time(), PDO::PARAM_INT);
            $stmt->execute();

            return true;
        });
    }

    /**
     * Create the session table and its indexes, using syntax appropriate for the
     * PDO driver (mysql, sqlite, pgsql; others get portable ANSI SQL).
     *
     * Errors are not swallowed silently: on failure this returns false, the
     * exception is available via getLastError(), and a message is sent to
     * error_log(). Idempotent (IF NOT EXISTS).
     *
     * @return bool True if the table and indexes exist after the call
     */
    public function createTable(): bool
    {
        $this->lastError = null;
        try {
            $this->guarded(function (): void {
                foreach ($this->getCreateTableStatements() as $sql) {
                    $this->pdo->exec($sql);
                }
            });
            return true;
        } catch (\PDOException $e) {
            $this->lastError = $e;
            error_log(sprintf(
                'DatabaseStorage::createTable() failed for table "%s": %s',
                $this->table,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function getCreateTableStatements(): array
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $t = $this->table;

        $dataType = match ($driver) {
            'pgsql' => 'BYTEA',
            'mysql' => 'MEDIUMBLOB',
            default => 'BLOB',
        };
        $intType = $driver === 'pgsql' ? 'BIGINT' : 'INTEGER';

        $create = "CREATE TABLE IF NOT EXISTS {$t} (
            session_id VARCHAR(128) NOT NULL PRIMARY KEY,
            session_data {$dataType} NOT NULL,
            expires_at {$intType} NOT NULL,
            created_at {$intType} NOT NULL,
            user_id VARCHAR(255) NULL
        )";

        if ($driver === 'mysql') {
            // MySQL has no CREATE INDEX IF NOT EXISTS; inline indexes are idempotent with the table.
            return [str_replace(
                'user_id VARCHAR(255) NULL',
                "user_id VARCHAR(255) NULL,\n            INDEX {$t}_expires_at_idx (expires_at),\n            INDEX {$t}_user_id_idx (user_id)",
                $create
            )];
        }

        return [
            $create,
            "CREATE INDEX IF NOT EXISTS {$t}_expires_at_idx ON {$t} (expires_at)",
            "CREATE INDEX IF NOT EXISTS {$t}_user_id_idx ON {$t} (user_id)",
        ];
    }

    /**
     * Destroy all sessions for a specific user
     *
     * @param string $userId The user identifier
     * @return bool True on success, false on failure
     */
    public function destroyUserSessions(string $userId): bool
    {
        return $this->guarded(function () use ($userId) {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE user_id = :user_id");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
            $stmt->execute();

            return true;
        });
    }

    /**
     * Set the user ID for a specific session
     *
     * @param SessionId $sessionId The session identifier
     * @param string $userId The user identifier
     * @return bool True on success, false on failure
     */
    public function setUserId(SessionId $sessionId, string $userId): bool
    {
        return $this->guarded(function () use ($sessionId, $userId) {
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->table} SET user_id = :user_id WHERE session_id = :id"
            );

            $stmt->bindValue(':id', $sessionId->toNative(), PDO::PARAM_STR);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        });
    }
}
