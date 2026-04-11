<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\Numbering\Service;

use Cake\Datasource\ConnectionManager;
use Compliance\Numbering\Exception\SequenceFormatFrozenException;
use Compliance\Numbering\Service\Sequencer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SequencerTest extends TestCase
{
    protected Sequencer $sequencer;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS compliance_sequences');
        $connection->execute('DROP TABLE IF EXISTS compliance_sequence_audit');
        $connection->execute(
            'CREATE TABLE compliance_sequences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope VARCHAR(100) NOT NULL,
                sequence_key VARCHAR(100) NOT NULL,
                year INTEGER NOT NULL,
                format VARCHAR(100) NOT NULL,
                current_value INTEGER NOT NULL DEFAULT 0,
                UNIQUE(scope, sequence_key, year)
            )',
        );
        $connection->execute(
            'CREATE TABLE compliance_sequence_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope VARCHAR(100) NOT NULL,
                sequence_key VARCHAR(100) NOT NULL,
                year INTEGER NOT NULL,
                allocated_value INTEGER NOT NULL,
                allocated_token VARCHAR(100) NOT NULL,
                committed INTEGER NOT NULL DEFAULT 0,
                created DATETIME NOT NULL
            )',
        );
        $this->sequencer = new Sequencer($connection);
    }

    public function testFirstAllocationReturnsFormattedValue(): void
    {
        $result = $this->sequencer->next('tenant-1', 'invoice', 2026, '{YYYY}-{####}');
        $this->assertSame('2026-0001', $result);
    }

    public function testSubsequentAllocationIncrements(): void
    {
        $this->sequencer->next('tenant-1', 'invoice', 2026, '{YYYY}-{####}');
        $this->sequencer->next('tenant-1', 'invoice', 2026, '{YYYY}-{####}');
        $third = $this->sequencer->next('tenant-1', 'invoice', 2026, '{YYYY}-{####}');
        $this->assertSame('2026-0003', $third);
    }

    public function testDifferentTenantsHaveIndependentCounters(): void
    {
        $this->sequencer->next('tenant-1', 'invoice', 2026, '{YYYY}-{####}');
        $result = $this->sequencer->next('tenant-2', 'invoice', 2026, '{YYYY}-{####}');
        $this->assertSame('2026-0001', $result);
    }

    public function testDifferentYearsHaveIndependentCounters(): void
    {
        $this->sequencer->next('tenant-1', 'invoice', 2025, '{YYYY}-{####}');
        $this->sequencer->next('tenant-1', 'invoice', 2025, '{YYYY}-{####}');
        $result = $this->sequencer->next('tenant-1', 'invoice', 2026, '{YYYY}-{####}');
        $this->assertSame('2026-0001', $result);
    }

    public function testDifferentSequenceKeysHaveIndependentCounters(): void
    {
        $this->sequencer->next('tenant-1', 'invoice', 2026, '{YYYY}-{####}');
        $result = $this->sequencer->next('tenant-1', 'receipt', 2026, '{YYYY}-{####}');
        $this->assertSame('2026-0001', $result);
    }

    public function testFormatIsFrozenAfterFirstUse(): void
    {
        $this->sequencer->next('tenant-1', 'invoice', 2026, '{YYYY}-{####}');
        $this->expectException(SequenceFormatFrozenException::class);
        $this->sequencer->next('tenant-1', 'invoice', 2026, 'RE-{YYYY}-{####}');
    }

    public function testFormatCanDifferPerScope(): void
    {
        $this->sequencer->next('tenant-1', 'invoice', 2026, '{YYYY}-{####}');
        $result = $this->sequencer->next('tenant-2', 'invoice', 2026, 'RE-{YYYY}-{####}');
        $this->assertSame('RE-2026-0001', $result);
    }

    public function testShortFormatUsesAppropriateWidth(): void
    {
        $result = $this->sequencer->next('tenant-1', 'short', 2026, '{##}');
        $this->assertSame('01', $result);
    }

    public function testSixDigitFormatUsesAppropriateWidth(): void
    {
        $result = $this->sequencer->next('tenant-1', 'wide', 2026, '{######}');
        $this->assertSame('000001', $result);
    }

    public function testYearOnlyFormatShortForm(): void
    {
        $result = $this->sequencer->next('tenant-1', 'slash', 2026, '{###}/{YY}');
        $this->assertSame('001/26', $result);
    }

    public function testAuditRowWrittenForEveryAllocation(): void
    {
        $this->sequencer->next('tenant-1', 'invoice', 2026, '{YYYY}-{####}');
        $this->sequencer->next('tenant-1', 'invoice', 2026, '{YYYY}-{####}');
        $connection = ConnectionManager::get('test');
        $rows = $connection->execute('SELECT COUNT(*) AS c FROM compliance_sequence_audit')->fetchAll('assoc');
        $this->assertSame(2, (int)$rows[0]['c']);
    }

    public function testRejectsFormatWithoutCounterPlaceholder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sequencer->next('tenant-1', 'invoice', 2026, 'just-text');
    }
}
