<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\Gobd;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Compliance\Gobd\Command\RetentionReportCommand;
use PHPUnit\Framework\TestCase;

class RetentionReportCommandTest extends TestCase
{
    public function testReportListsRegisteredTables(): void
    {
        $command = new RetentionReportCommand();
        $command->registerTable('invoices', 10, 'booked_on');
        $command->registerTable('ledger_entries', 10, 'created');

        [$out] = $this->runCommand($command);
        $this->assertStringContainsString('invoices', $out);
        $this->assertStringContainsString('ledger_entries', $out);
        $this->assertStringContainsString('10', $out);
    }

    public function testReportShowsNoneRegisteredMessageWhenEmpty(): void
    {
        $command = new RetentionReportCommand();
        [$out, $code] = $this->runCommand($command);
        $this->assertStringContainsString('No tables registered', $out);
        $this->assertSame(0, $code);
    }

    public function testReportIncludesCustomDateField(): void
    {
        $command = new RetentionReportCommand();
        $command->registerTable('donations', 7, 'donated_on');
        [$out] = $this->runCommand($command);
        $this->assertStringContainsString('donated_on', $out);
        $this->assertStringContainsString('7', $out);
    }

    /**
     * @return array{0: string, 1: int}
     */
    protected function runCommand(RetentionReportCommand $command): array
    {
        $output = new StubConsoleOutput();
        $io = new ConsoleIo($output, $output, new StubConsoleInput([]));
        $args = new Arguments([], [], []);
        $code = $command->execute($args, $io);

        return [implode("\n", $output->messages()), (int)$code];
    }
}
