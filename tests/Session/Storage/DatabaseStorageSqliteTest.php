<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session\Storage;

use Dzentota\Session\Storage\DatabaseStorage;
use Dzentota\Session\Value\SessionId;
use PDO;
use PHPUnit\Framework\TestCase;

/** @requires extension pdo_sqlite */
class DatabaseStorageSqliteTest extends TestCase
{
    public function testCreateTableAndRoundTripOnSqlite(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $storage = new DatabaseStorage($pdo);
        $this->assertTrue($storage->createTable());
        $this->assertNull($storage->getLastError());
        $this->assertTrue($storage->createTable(), 'idempotent');

        $idx = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='sessions'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('sessions_expires_at_idx', $idx);
        $this->assertContains('sessions_user_id_idx', $idx);

        $id = SessionId::generate();
        $storage->write($id, 'payload', 60);
        $this->assertSame('payload', $storage->read($id));
        $this->assertTrue($storage->setUserId($id, 'u1'));
        $storage->destroyUserSessions('u1');
        $this->assertNull($storage->read($id));
    }

    public function testDoesNotMutateCallerErrorMode(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $storage = new DatabaseStorage($pdo);
        $storage->createTable();
        $storage->read(SessionId::generate());
        $this->assertSame(PDO::ERRMODE_SILENT, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testErrorsInSilentModeStillThrow(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $storage = new DatabaseStorage($pdo, 'missing_table');
        $this->expectException(\PDOException::class);
        $storage->read(SessionId::generate());
    }

    public function testCreateTableFailureIsReportedNotSwallowed(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE VIEW sessions AS SELECT 1 AS x');
        $storage = new DatabaseStorage($pdo);
        $this->assertFalse($storage->createTable());
        $this->assertInstanceOf(\PDOException::class, $storage->getLastError());
    }
}
