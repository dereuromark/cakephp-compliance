<?php

declare(strict_types=1);

namespace Compliance\Gobd;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Writes GoBD-compliant audit entries into the `compliance_audit_chain`
 * table, linking every row to the previous one via a SHA-256 hash.
 *
 * This is a self-contained persister. It has no dependency on any other
 * audit plugin — callers can wire it up from a model behavior, a
 * controller, a queue job, or anywhere else in the request lifecycle. For
 * an entity-driven `addBehavior()` experience, see
 * {@see \Compliance\Model\Behavior\AuditChainBehavior}.
 *
 * ## Concurrency
 *
 * The writer wraps every `log()` call in a transaction and reads the
 * current chain tail with `SELECT ... FOR UPDATE` on MySQL and Postgres
 * so concurrent writers serialize on the tail. SQLite's file-level
 * locking provides the equivalent guarantee without an explicit hint.
 *
 * For production deployments, back the `compliance_audit_chain` table
 * with a DB-level `BEFORE UPDATE` / `BEFORE DELETE` trigger that rejects
 * all writes (this writer only issues INSERTs). See docs/Gobd.md for a
 * trigger template.
 */
class AuditChainWriter
{
    /**
     * @var string
     */
    public const TABLE = 'compliance_audit_chain';

    protected Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        if ($connection === null) {
            $resolved = ConnectionManager::get('default');
            if (!$resolved instanceof Connection) {
                throw new RuntimeException(
                    'AuditChainWriter requires a Cake\\Database\\Connection; got '
                    . get_class($resolved) . '.',
                );
            }
            $connection = $resolved;
        }
        $this->connection = $connection;
    }

    /**
     * Append a single audit entry to the chain.
     *
     * @param string $eventType Create, update, delete, or any custom verb
     * @param string $source Logical name of the record being audited (e.g. `Invoices`)
     * @param string|null $targetId Primary key of the audited record, as a string
     * @param array<string, mixed> $payload Arbitrary structured data for the audit trail
     * @param string|null $transactionId Opaque identifier to group entries from one logical unit of work
     */
    public function log(
        string $eventType,
        string $source,
        ?string $targetId,
        array $payload,
        ?string $transactionId = null,
    ): void {
        $this->assertHashChainReady();

        $lockHandle = $this->acquireChainWriteLock();
        try {
            $this->connection->transactional(function () use ($eventType, $source, $targetId, $payload, $transactionId): void {
                $prevHash = $this->loadTailHashForUpdate();
                $hash = HashChain::hash($prevHash, $payload);

                $this->connection->insert(self::TABLE, [
                    'transaction_id' => $transactionId,
                    'event_type' => $eventType,
                    'source' => $source,
                    'target_id' => $targetId,
                    'payload' => $this->encode($payload),
                    'prev_hash' => $prevHash,
                    'hash' => $hash,
                    'created' => (new DateTime())->format('Y-m-d H:i:s'),
                ]);
            });
        } finally {
            $this->releaseChainWriteLock($lockHandle);
        }
    }

    /**
     * Append many entries in a single transaction, linking them into the
     * chain in argument order. Use when a logical unit of work produces
     * several audit rows and you want them atomic.
     *
     * @param array<int, array{event_type: string, source: string, target_id?: string|null, payload: array<string, mixed>, transaction_id?: string|null}> $entries
     */
    public function logMany(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $this->assertHashChainReady();

        $lockHandle = $this->acquireChainWriteLock();
        try {
            $this->connection->transactional(function () use ($entries): void {
                $prevHash = $this->loadTailHashForUpdate();

                foreach ($entries as $entry) {
                    $payload = $entry['payload'];
                    $hash = HashChain::hash($prevHash, $payload);

                    $this->connection->insert(self::TABLE, [
                        'transaction_id' => $entry['transaction_id'] ?? null,
                        'event_type' => $entry['event_type'],
                        'source' => $entry['source'],
                        'target_id' => $entry['target_id'] ?? null,
                        'payload' => $this->encode($payload),
                        'prev_hash' => $prevHash,
                        'hash' => $hash,
                        'created' => (new DateTime())->format('Y-m-d H:i:s'),
                    ]);

                    $prevHash = $hash;
                }
            });
        } finally {
            $this->releaseChainWriteLock($lockHandle);
        }
    }

    /**
     * Read the current chain tail with a row-level lock so concurrent
     * writers serialize on it instead of reading stale tails.
     *
     * MySQL/Postgres: `SELECT ... FOR UPDATE` gives us the lock.
     * SQLite: the writer is already inside a transaction and SQLite's
     *   database-level lock provides the equivalent guarantee, so the
     *   `FOR UPDATE` clause is safely omitted there.
     * SQL Server: callers must wrap `log()` in a SERIALIZABLE transaction
     *   for the equivalent guarantee — undocumented here on purpose; the
     *   plugin does not issue `WITH (UPDLOCK)` hints today.
     */
    protected function loadTailHashForUpdate(): ?string
    {
        $driverClass = $this->connection->getDriver()::class;
        $sql = 'SELECT hash FROM ' . self::TABLE . ' ORDER BY id DESC LIMIT 1';
        if (str_contains($driverClass, 'Mysql') || str_contains($driverClass, 'Postgres')) {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->connection->execute($sql)->fetch('assoc');
        if ($row === false || !isset($row['hash'])) {
            return null;
        }

        return (string)$row['hash'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    protected function assertHashChainReady(): void
    {
        try {
            $schema = $this->connection->getSchemaCollection()->describe(self::TABLE);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'GoBD audit chain table is missing. Run the compliance migration before writing audit entries.',
                previous: $exception,
            );
        }

        $columns = $schema->columns();
        foreach (['payload', 'prev_hash', 'hash'] as $column) {
            if (!in_array($column, $columns, true)) {
                throw new InvalidArgumentException(
                    sprintf('GoBD audit chain table is missing required column `%s`.', $column),
                );
            }
        }
    }

    protected function acquireChainWriteLock(): ?string
    {
        $driverClass = $this->connection->getDriver()::class;
        $lockName = 'compliance_audit_chain:' . self::TABLE;

        if (str_contains($driverClass, 'Mysql')) {
            $result = $this->connection
                ->execute('SELECT GET_LOCK(?, 10) AS acquired', [$lockName])
                ->fetch('assoc');
            if ((int)($result['acquired'] ?? 0) !== 1) {
                throw new RuntimeException('Failed to acquire GoBD audit-chain write lock.');
            }

            return $lockName;
        }

        if (str_contains($driverClass, 'Postgres')) {
            [$key1, $key2] = $this->advisoryLockKeys($lockName);
            $this->connection->execute('SELECT pg_advisory_lock(?, ?)', [$key1, $key2]);

            return $lockName;
        }

        return null;
    }

    protected function releaseChainWriteLock(?string $lockHandle): void
    {
        if ($lockHandle === null) {
            return;
        }

        $driverClass = $this->connection->getDriver()::class;
        if (str_contains($driverClass, 'Mysql')) {
            $this->connection->execute('SELECT RELEASE_LOCK(?)', [$lockHandle]);

            return;
        }

        if (str_contains($driverClass, 'Postgres')) {
            [$key1, $key2] = $this->advisoryLockKeys($lockHandle);
            $this->connection->execute('SELECT pg_advisory_unlock(?, ?)', [$key1, $key2]);
        }
    }

    /**
     * @return array{int, int}
     */
    protected function advisoryLockKeys(string $lockName): array
    {
        return [
            $this->toSignedInt32(crc32('compliance')),
            $this->toSignedInt32(crc32($lockName)),
        ];
    }

    protected function toSignedInt32(int $value): int
    {
        if ($value <= 0x7fffffff) {
            return $value;
        }

        return $value - 0x100000000;
    }
}
