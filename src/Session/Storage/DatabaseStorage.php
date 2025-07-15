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
     * @param mixed $encryptor Optional callback for encrypting data (function(string $data): string)
     * @param mixed $decryptor Optional callback for decrypting data (function(string $encryptedData): string)
     */
    public function __construct(
        private PDO $pdo,
        private string $table = 'sessions',
        private mixed $encryptor = null,
        private mixed $decryptor = null
    ) {
        // Ensure PDO throws exceptions
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * {@inheritDoc}
     */
    public function read(SessionId $sessionId): ?string
    {
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
    }

    /**
     * {@inheritDoc}
     */
    public function write(SessionId $sessionId, string $data, int $lifetime): bool
    {
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
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(SessionId $sessionId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE session_id = :id");
        $stmt->bindValue(':id', $sessionId->toNative(), PDO::PARAM_STR);
        $stmt->execute();

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function gc(int $maxLifetime): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires_at < :time");
        $stmt->bindValue(':time', time(), PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * Create the database table for session storage
     *
     * @return bool True if the table was created successfully
     */
    public function createTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            session_id VARCHAR(128) PRIMARY KEY,
            session_data BLOB NOT NULL,
            expires_at INT NOT NULL,
            created_at INT NOT NULL,
            user_id VARCHAR(255) NULL,
            INDEX (expires_at),
            INDEX (user_id)
        )";

        try {
            $this->pdo->exec($sql);
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Destroy all sessions for a specific user
     *
     * @param string $userId The user identifier
     * @return bool True on success, false on failure
     */
    public function destroyUserSessions(string $userId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        return true;
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
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET user_id = :user_id WHERE session_id = :id"
        );

        $stmt->bindValue(':id', $sessionId->toNative(), PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
}
