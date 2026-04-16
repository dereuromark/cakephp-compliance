<?php

declare(strict_types=1);

namespace Compliance\Gobd;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

class ChainVerifier
{
    protected Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        if ($connection === null) {
            $resolved = ConnectionManager::get('default');
            if (!$resolved instanceof Connection) {
                throw new RuntimeException('ChainVerifier requires a Cake\\Database\\Connection.');
            }
            $connection = $resolved;
        }

        $this->connection = $connection;
    }

    public function verify(int $chunkSize = 500): ChainVerificationResult
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException(
                sprintf('ChainVerifier chunk size must be >= 1, got %d.', $chunkSize),
            );
        }

        $this->assertHashChainReady();

        $rowsChecked = 0;
        $expectedPrev = null;
        $lastId = 0;

        while (true) {
            $rows = $this->connection
                ->execute(
                    'SELECT id, payload, prev_hash, hash FROM '
                    . AuditChainWriter::TABLE
                    . ' WHERE id > :lastId ORDER BY id ASC LIMIT :chunkSize',
                    ['lastId' => $lastId, 'chunkSize' => $chunkSize],
                    ['lastId' => 'integer', 'chunkSize' => 'integer'],
                )
                ->fetchAll('assoc');

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $rowsChecked++;
                $id = (int)$row['id'];
                $storedPrev = $row['prev_hash'] !== null ? (string)$row['prev_hash'] : null;
                $storedHash = (string)$row['hash'];

                if ($storedPrev !== $expectedPrev) {
                    return ChainVerificationResult::broken(
                        $id,
                        $rowsChecked,
                        sprintf(
                            'prev_hash mismatch: row stores %s, expected %s',
                            $storedPrev === null ? 'NULL' : substr($storedPrev, 0, 16) . '...',
                            $expectedPrev === null ? 'NULL' : substr($expectedPrev, 0, 16) . '...',
                        ),
                    );
                }

                try {
                    $payload = $this->decodePayload((string)$row['payload']);
                } catch (JsonException $exception) {
                    return ChainVerificationResult::broken(
                        $id,
                        $rowsChecked,
                        sprintf('invalid JSON payload: %s', $exception->getMessage()),
                    );
                } catch (RuntimeException $exception) {
                    return ChainVerificationResult::broken(
                        $id,
                        $rowsChecked,
                        $exception->getMessage(),
                    );
                }
                $recomputed = HashChain::hash($storedPrev, $payload);
                if (!hash_equals($recomputed, $storedHash)) {
                    return ChainVerificationResult::broken(
                        $id,
                        $rowsChecked,
                        sprintf(
                            'hash mismatch: stored %s, recomputed %s',
                            substr($storedHash, 0, 16) . '...',
                            substr($recomputed, 0, 16) . '...',
                        ),
                    );
                }

                $expectedPrev = $storedHash;
                $lastId = $id;
            }

            if (count($rows) < $chunkSize) {
                break;
            }
        }

        return ChainVerificationResult::intact($rowsChecked);
    }

    /**
     * @throws \RuntimeException if the decoded payload is not an array/object
     *
     * @return array<string, mixed>
     */
    protected function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('expected JSON array or object payload');
        }

        return $decoded;
    }

    protected function assertHashChainReady(): void
    {
        try {
            $schema = $this->connection->getSchemaCollection()->describe(AuditChainWriter::TABLE);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'GoBD audit chain table is missing. Run the compliance migration before verifying the chain.',
                previous: $exception,
            );
        }

        $columns = $schema->columns();
        foreach (['id', 'payload', 'prev_hash', 'hash'] as $column) {
            if (!in_array($column, $columns, true)) {
                throw new RuntimeException(
                    sprintf('GoBD audit chain table is missing required column `%s`.', $column),
                );
            }
        }
    }
}
