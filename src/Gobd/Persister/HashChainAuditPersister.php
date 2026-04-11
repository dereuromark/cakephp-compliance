<?php

declare(strict_types=1);

namespace Compliance\Gobd\Persister;

use AuditStash\EventInterface;
use AuditStash\PersisterInterface;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Compliance\Gobd\HashChain;
use RuntimeException;

/**
 * Audit-stash PersisterInterface implementation that writes each audit event
 * to a dedicated `compliance_audit_chain` table with a SHA-256 hash linking
 * every row to the previous one.
 *
 * The chain gives GoBD "revisionssichere Speicherung" (tamper-evident
 * storage) with a single hash-chain proof: any mutation of a historical row
 * invalidates `HashChain::verify()` on the whole chain.
 *
 * This persister is intended to plug into audit-stash's `AuditLogBehavior`:
 *
 * ```php
 * $this->addBehavior('AuditStash.AuditLog', [
 *     'persister' => \Compliance\Gobd\Persister\HashChainAuditPersister::class,
 * ]);
 * ```
 *
 * For production deployments, back the `compliance_audit_chain` table with a
 * DB-level `BEFORE UPDATE` / `BEFORE DELETE` trigger that rejects all writes
 * (the persister only INSERTs). See `docs/migration-templates.md`.
 */
class HashChainAuditPersister implements PersisterInterface
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
                    'HashChainAuditPersister requires a Cake\\Database\\Connection; '
                    . 'got ' . get_class($resolved) . '.',
                );
            }
            $connection = $resolved;
        }
        $this->connection = $connection;
    }

    /**
     * @param array<\AuditStash\EventInterface> $auditLogs
     */
    public function logEvents(array $auditLogs): void
    {
        if ($auditLogs === []) {
            return;
        }

        $prevHash = $this->loadLatestHash();

        $this->connection->transactional(function () use ($auditLogs, &$prevHash): void {
            foreach ($auditLogs as $event) {
                $payload = $this->buildPayload($event);
                $encoded = $this->encode($payload);
                $hash = HashChain::hash($prevHash, $payload);

                $this->connection->insert(self::TABLE, [
                    'transaction_id' => $event->getTransactionId(),
                    'event_type' => $event->getEventType(),
                    'source' => $event->getSourceName(),
                    'target_id' => (string)$event->getId(),
                    'payload' => $encoded,
                    'prev_hash' => $prevHash,
                    'hash' => $hash,
                    'created' => (new DateTime())->format('Y-m-d H:i:s'),
                ]);

                $prevHash = $hash;
            }
        });
    }

    /**
     * @param \AuditStash\EventInterface $event
     *
     * @return array<string, mixed>
     */
    protected function buildPayload(EventInterface $event): array
    {
        /** @var array<string, mixed> $encoded */
        $encoded = $event->jsonSerialize();

        return $encoded;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \RuntimeException
     */
    protected function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('HashChainAuditPersister: payload is not JSON-encodable.');
        }

        return $encoded;
    }

    protected function loadLatestHash(): ?string
    {
        $row = $this->connection
            ->execute('SELECT hash FROM ' . self::TABLE . ' ORDER BY id DESC LIMIT 1')
            ->fetch('assoc');

        if ($row === false) {
            return null;
        }

        return (string)$row['hash'];
    }
}
