<?php

declare(strict_types=1);

namespace Compliance\Gobd\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

/**
 * `bin/cake gobd retention_report`
 *
 * Lists every table that has been registered with `GobdBehavior` along with
 * its retention window in years and the date field used to evaluate the
 * retention cutoff. Intended as a sanity check before an audit, so the
 * Kassenwart can see at a glance which data is protected and for how long.
 *
 * Registration is explicit via `registerTable()` — the command does not
 * auto-discover Tables because auto-discovery couples the command to the
 * application's bootstrap sequence. Consumers register their tables from
 * their `Application::bootstrap()` or from a plugin bootstrap hook.
 */
class RetentionReportCommand extends Command
{
    /**
     * @var array<int, array{table: string, years: int, field: string}>
     */
    protected array $registered = [];

    /**
     * Register a table that has `GobdBehavior` attached. Call from your app
     * bootstrap before running this command.
     */
    public function registerTable(string $table, int $retentionYears, string $dateField): void
    {
        $this->registered[] = [
            'table' => $table,
            'years' => $retentionYears,
            'field' => $dateField,
        ];
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        if ($this->registered === []) {
            $io->warning('No tables registered with RetentionReportCommand.');

            return static::CODE_SUCCESS;
        }

        $io->out('GoBD retention report:');
        $io->out('');
        foreach ($this->registered as $row) {
            $io->out(sprintf(
                '  %-40s  %2d years  (date field: %s)',
                $row['table'],
                $row['years'],
                $row['field'],
            ));
        }

        return static::CODE_SUCCESS;
    }
}
