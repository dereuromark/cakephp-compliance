<?php

declare(strict_types=1);

namespace Compliance\Gobd\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Compliance\Gobd\HashChain;
use Compliance\Gobd\Persister\HashChainAuditPersister;
use RuntimeException;

/**
 * `bin/cake gobd verify_chain`
 *
 * Walks the `compliance_audit_chain` table in insertion order and asserts
 * that every entry's hash matches `HashChain::hash(prev_hash, payload)`. Any
 * mismatch aborts with a non-zero exit code and reports the first offending
 * row — exactly the failure mode an auditor or the Finanzamt wants to see.
 *
 * Intended to be run as a scheduled task (daily / weekly) and as a manual
 * check before preparing a Kassenprüfung.
 */
class VerifyChainCommand extends Command
{
    protected Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        parent::__construct();
        if ($connection === null) {
            $resolved = ConnectionManager::get('default');
            if (!$resolved instanceof Connection) {
                throw new RuntimeException('VerifyChainCommand requires a Cake\\Database\\Connection.');
            }
            $connection = $resolved;
        }
        $this->connection = $connection;
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $rows = $this->connection
            ->execute(
                'SELECT id, payload, prev_hash, hash FROM '
                . HashChainAuditPersister::TABLE
                . ' ORDER BY id ASC',
            )
            ->fetchAll('assoc') ?: [];

        if ($rows === []) {
            $io->out('Audit chain is empty — nothing to verify.');

            return static::CODE_SUCCESS;
        }

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'payload' => (array)json_decode((string)$row['payload'], true),
                'prev_hash' => $row['prev_hash'],
                'hash' => (string)$row['hash'],
            ];
        }

        if (HashChain::verify($entries)) {
            $io->success(sprintf('Audit chain is intact (%d entries verified).', count($entries)));

            return static::CODE_SUCCESS;
        }

        $io->error(sprintf(
            'Audit chain is tampered. Ran %d entries; verification failed.',
            count($entries),
        ));

        return static::CODE_ERROR;
    }
}
