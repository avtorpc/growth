<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class TestDataResetRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function reset(int $shopId): void
    {
        $this->connection->transactional(function () use ($shopId): void {
            // New import runs need a shared FK lock on this shop and wait for reset.
            if ($this->connection->fetchOne('SELECT id FROM shops WHERE id = ? FOR UPDATE', [$shopId]) === false) {
                throw new \OutOfBoundsException('Магазин не найден.');
            }
            if ($this->connection->fetchOne("SELECT id FROM order_imports WHERE shop_id = ? AND status = 'processing' LIMIT 1 FOR UPDATE", [$shopId]) !== false) {
                throw new \LogicException('Импорт ещё выполняется. Дождитесь завершения и повторите очистку.');
            }
            // Foreign keys remove log records, rejections and order items.
            $this->connection->delete('order_imports', ['shop_id' => $shopId]);
            $this->connection->delete('orders', ['shop_id' => $shopId]);
        });
    }
}
