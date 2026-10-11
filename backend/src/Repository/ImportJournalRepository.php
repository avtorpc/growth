<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class ImportJournalRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function start(int $shopId, int $total): int
    {
        $this->connection->insert('order_imports', ['shop_id' => $shopId, 'total' => $total]);
        return (int) $this->connection->lastInsertId();
    }

    public function record(int $importId, mixed $raw, array $result): void
    {
        $write = function () use ($importId, $raw, $result): void {
            $payload = $this->json($raw);
            $this->connection->insert('order_import_records', [
                'import_id' => $importId, 'position' => $result['index'],
                'marketplace_id' => $result['marketplace_id'],
                'order_id' => $result['order']['id'] ?? null,
                'outcome' => $result['outcome'], 'raw_payload' => $payload,
                'result_payload' => $this->json($result),
            ]);
            if ($result['outcome'] === 'rejected') {
                $this->connection->insert('rejected_orders', [
                    'import_record_id' => (int) $this->connection->lastInsertId(),
                    'reason_code' => $result['reason']['code'],
                    'reason_message' => $result['reason']['message'], 'raw_payload' => $payload,
                ]);
            }
        };
        // Successful orders call this from their own transaction. Rejections have no order.
        if ($this->connection->isTransactionActive()) {
            $write();
        } else {
            $this->connection->transactional($write);
        }
    }

    public function finish(int $importId, array $summary, string $status = 'completed'): void
    {
        $this->connection->executeStatement(
            'UPDATE order_imports SET status = ?, summary = ?, finished_at = UTC_TIMESTAMP(6) WHERE id = ?',
            [$status, $this->json($summary), $importId],
        );
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION, 128);
    }
}
