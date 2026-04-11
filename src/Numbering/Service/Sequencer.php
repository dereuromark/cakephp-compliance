<?php

declare(strict_types=1);

namespace Compliance\Numbering\Service;

use Cake\Database\Connection;
use Cake\I18n\DateTime;
use Compliance\Numbering\Exception\SequenceFormatFrozenException;
use InvalidArgumentException;

/**
 * Gap-free, race-safe per-tenant-per-year sequence generator.
 *
 * Allocates the next integer value for a given (scope, sequence_key, year)
 * tuple under a transactional lock, stores the frozen format on first use,
 * and writes an append-only audit row for every allocation so rolled-back
 * transactions leave a traceable gap explanation.
 *
 * Format tokens:
 *   {YYYY} four-digit year
 *   {YY} two-digit year
 *   {##...#} zero-padded counter (width = number of hashes)
 */
class Sequencer
{
    public function __construct(protected Connection $connection)
    {
    }

    /**
     * Allocate the next number for the given sequence and return it formatted.
     *
     * @param string $scope Tenant or account identifier
     * @param string $sequenceKey Logical sequence name (invoice, receipt, ...)
     * @param int $year Billing period year
     * @param string $format Format template with `{YYYY}`, `{YY}`, `{##...#}` placeholders
     */
    public function next(string $scope, string $sequenceKey, int $year, string $format): string
    {
        $counterWidth = $this->parseCounterWidth($format);

        /** @var array<string, mixed> $allocated */
        $allocated = [];
        $this->connection->transactional(
            function () use ($scope, $sequenceKey, $year, $format, &$allocated): void {
                $row = $this->connection->execute(
                    'SELECT id, format, current_value FROM compliance_sequences'
                    . ' WHERE scope = :scope AND sequence_key = :key AND year = :year',
                    ['scope' => $scope, 'key' => $sequenceKey, 'year' => $year],
                )->fetch('assoc');

                if ($row === false) {
                    $this->connection->insert('compliance_sequences', [
                        'scope' => $scope,
                        'sequence_key' => $sequenceKey,
                        'year' => $year,
                        'format' => $format,
                        'current_value' => 1,
                    ]);
                    $allocated['value'] = 1;
                    $allocated['format'] = $format;

                    return;
                }

                if ($row['format'] !== $format) {
                    throw new SequenceFormatFrozenException(sprintf(
                        'Sequence "%s" for scope "%s" year %d is frozen to format "%s"; cannot switch to "%s".',
                        $sequenceKey,
                        $scope,
                        $year,
                        (string)$row['format'],
                        $format,
                    ));
                }

                $next = (int)$row['current_value'] + 1;
                $this->connection->update(
                    'compliance_sequences',
                    ['current_value' => $next],
                    ['id' => $row['id']],
                );
                $allocated['value'] = $next;
                $allocated['format'] = $format;
            },
        );

        $token = $this->render((string)$allocated['format'], $year, (int)$allocated['value'], $counterWidth);

        $this->connection->insert('compliance_sequence_audit', [
            'scope' => $scope,
            'sequence_key' => $sequenceKey,
            'year' => $year,
            'allocated_value' => (int)$allocated['value'],
            'allocated_token' => $token,
            'committed' => 1,
            'created' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * Return the width (number of digits) of the counter placeholder in $format.
     *
     * @throws \InvalidArgumentException when no `{##...#}` placeholder is present
     */
    protected function parseCounterWidth(string $format): int
    {
        if (!preg_match('/\{(#+)\}/', $format, $matches)) {
            throw new InvalidArgumentException(sprintf(
                'Format "%s" must contain a counter placeholder like "{####}".',
                $format,
            ));
        }

        return strlen($matches[1]);
    }

    protected function render(string $format, int $year, int $value, int $width): string
    {
        $counterPlaceholder = '{' . str_repeat('#', $width) . '}';
        $padded = str_pad((string)$value, $width, '0', STR_PAD_LEFT);
        $rendered = str_replace($counterPlaceholder, $padded, $format);
        $rendered = str_replace('{YYYY}', sprintf('%04d', $year), $rendered);
        $rendered = str_replace('{YY}', sprintf('%02d', $year % 100), $rendered);

        return $rendered;
    }
}
