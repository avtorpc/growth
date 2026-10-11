<?php

declare(strict_types=1);

namespace App\Import;

use App\Repository\OrderRepository;
use App\Repository\ImportJournalRepository;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;

final class ImportService
{
    public function __construct(
        private readonly OrderRepository $repository,
        private readonly OrderValidator $validator,
        private readonly DeliveryCalculator $delivery,
        private readonly LoggerInterface $logger,
        private readonly ImportJournalRepository $journal,
    ) {
    }

    public function import(int $shopId, array $orders, ?array $sourceOrders = null): array
    {
        $shop = $this->repository->shop($shopId);
        if ($shop === false) {
            throw new \OutOfBoundsException('Магазин не найден.');
        }
        $tariffs = $this->repository->tariffs($shopId);
        $seen = [];
        $results = [];
        $summary = array_fill_keys(['created', 'updated', 'unchanged', 'duplicate', 'rejected'], 0);
        $importId = $this->journal->start($shopId, count($orders));
        try {
            foreach ($orders as $index => $raw) {
                $source = $sourceOrders === null ? $raw : $sourceOrders[$index];
                $logged = false;
                $id = null;
                try {
                    $id = $this->validator->identifier(is_array($raw) ? ($raw['id'] ?? null) : null);
                    // Mark an ID before validating its data: even a rejected first occurrence wins.
                    if (isset($seen[$id])) {
                        $result = $this->result($index, $id, 'duplicate', 'duplicate_in_batch', 'Повтор ID в одной пачке; обработано первое вхождение.');
                    } else {
                        $seen[$id] = true;
                        $validated = $this->validator->validate($raw, $shop['timezone']);
                        $order = $validated['order'] + $this->delivery->calculate($validated, $shop, $tariffs);
                        $saved = $this->repository->save($shopId, $order, $validated['items'],
                            function (array $saved) use ($importId, $source, $index, $id): void {
                                $this->journal->record($importId, $source, $this->savedResult($index, $id, $saved));
                            });
                        $logged = true;
                        $result = $this->savedResult($index, $id, $saved);
                    }
                } catch (OrderRejected $exception) {
                    $result = $this->result($index, $id, 'rejected', $exception->reasonCode, $exception->getMessage());
                } catch (Exception $exception) {
                    $this->logger->error('Order import failed.', ['shop_id' => $shopId, 'marketplace_id' => $id, 'exception' => $exception]);
                    $result = $this->result($index, $id, 'rejected', 'storage_error', 'Заказ не удалось сохранить. Повторите импорт.');
                }
                if (!$logged) {
                    $this->journal->record($importId, $source, $result);
                }
                ++$summary[$result['outcome']];
                $results[] = $result;
            }
            $this->journal->finish($importId, $summary);
        } catch (\Throwable $exception) {
            try {
                $this->journal->finish($importId, $summary, 'failed');
            } catch (\Throwable $journalException) {
                $this->logger->error('Cannot finish import journal.', ['import_id' => $importId, 'exception' => $journalException]);
            }
            throw $exception;
        }
        return ['import_id' => $importId, 'shop_id' => $shopId, 'total' => count($orders), 'summary' => $summary, 'results' => $results];
    }

    private function savedResult(int $index, string $id, array $saved): array
    {
        $result = $this->result($index, $id, $saved['outcome'], $saved['reason']['code'], $saved['reason']['message']);
        $row = $saved['order'];
        $result['order'] = [
            'id' => (int) $row['id'], 'status' => $row['status'],
            'needs_review' => (bool) $row['needs_review'],
            'items_total_kopecks' => (int) $row['items_total_kopecks'],
            'marketplace_total_kopecks' => (int) $row['marketplace_total_kopecks'],
            'delivery_total_kopecks' => (int) $row['delivery_total_kopecks'],
            'grand_total_kopecks' => (int) $row['grand_total_kopecks'],
        ];
        if ($row['needs_review']) {
            $result['warnings'][] = ['code' => 'total_mismatch', 'message' => 'Сумма позиций отличается от total маркетплейса; нужна проверка.'];
        }
        return $result;
    }

    private function result(int $index, ?string $id, string $outcome, string $code, string $message): array
    {
        return [
            'index' => $index + 1, 'marketplace_id' => $id, 'outcome' => $outcome,
            'reason' => ['code' => $code, 'message' => $message], 'warnings' => [],
        ];
    }
}
