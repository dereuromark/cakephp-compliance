<?php
declare(strict_types=1);

namespace Compliance\Gobd\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

/**
 * RetentionReportCommand.
 *
 * @todo Implement. See README for intended scope.
 */
class RetentionReportCommand extends Command
{
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $io->warning('Not implemented yet.');

        return static::CODE_SUCCESS;
    }
}
