<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class OrderRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shop(int $shopId): array|false
    {
        return $this->connection->fetchAssociative('SELECT * FROM shops WHERE id = ?', [$shopId]);
    }

    public function tariffs(int $shopId): array
    {
        $tariffs = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM delivery_tariffs WHERE shop_id = ?', [$shopId]) as $tariff) {
            $tariffs[$tariff['district_code']] = $tariff;
        }
        return $tariffs;
    }

    public function save(int $shopId, array $order, array $items): array
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->connection->transactional(function () use ($shopId, $order, $items): array {
                    $params = [$shopId, $order['marketplace_id']];
                    $query = 'SELECT * FROM orders WHERE shop_id = ? AND marketplace_id = ?';
                    // Do not gap-lock an absent order. UNIQUE resolves concurrent inserts.
                    $existing = $this->connection->fetchAssociative($query, $params);
                    if ($existing !== false) {
                        // A locking read sees the latest committed status, including a concurrent update.
                        $existing = $this->connection->fetchAssociative($query.' FOR UPDATE', $params);
                    }
                    if ($existing === false) {
                        $this->connection->insert('orders', ['shop_id' => $shopId] + $order);
                        $id = (int) $this->connection->lastInsertId();
                        foreach ($items as $item) {
                            $this->connection->insert('order_items', ['order_id' => $id] + $item);
                        }
                        $outcome = 'created';
                        $code = 'order_created';
                        $message = 'Заказ создан.';
                    } else {
                        $id = (int) $existing['id'];
                        $outcome = 'unchanged';
                        $code = 'status_unchanged';
                        $message = 'Статус не изменился.';
                        if ($existing['status'] !== $order['status']) {
                            if (in_array($existing['status'], ['delivered', 'cancelled'], true)) {
                                $code = 'final_status_preserved';
                                $message = 'Сохранён финальный статус заказа.';
                            } else {
                                $this->connection->executeStatement(
                                    'UPDATE orders SET status = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?',
                                    [$order['status'], $id],
                                );
                                $outcome = 'updated';
                                $code = 'status_updated';
                                $message = 'Обновлён только статус заказа.';
                            }
                        }
                    }
                    return [
                        'outcome' => $outcome,
                        'reason' => ['code' => $code, 'message' => $message],
                        'order' => $this->connection->fetchAssociative('SELECT * FROM orders WHERE id = ?', [$id]),
                    ];
                });
            } catch (UniqueConstraintViolationException|RetryableException $exception) {
                if ($attempt >= 3) {
                    throw $exception;
                }
                // Retry the whole order transaction after rollback, with a fresh snapshot.
                usleep($attempt * 10_000);
            }
        }
    }
}
