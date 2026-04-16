<?php

declare(strict_types=1);

namespace Compliance\Gobd\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Compliance\Gobd\ChainVerifier;
use RuntimeException;
use Throwable;

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
        try {
            $result = (new ChainVerifier($this->connection))->verify();
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return static::CODE_ERROR;
        }

        if ($result->rowsChecked === 0) {
            $io->out('Audit chain is empty — nothing to verify.');

            return static::CODE_SUCCESS;
        }

        if ($result->intact) {
            $io->success(sprintf('Audit chain is intact (%d entries verified).', $result->rowsChecked));

            return static::CODE_SUCCESS;
        }

        $io->error(sprintf(
            'Audit chain is tampered at row id=%d (position %d): %s',
            (int)$result->brokenRowId,
            $result->rowsChecked,
            (string)$result->reason,
        ));

        return static::CODE_ERROR;
    }
}
