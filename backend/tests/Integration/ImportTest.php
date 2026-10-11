<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Import\ImportService;
use App\Kernel;
use App\Tests\Support\TestData;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ImportTest extends TestCase
{
    private Connection $connection;
    private ImportService $importer;
    private int $shopId;

    protected function setUp(): void
    {
        $this->connection = TestData::connection();
        $this->connection->insert('shops', [
            'code' => 'import-test-'.bin2hex(random_bytes(8)), 'name' => 'Проверка импорта',
            'timezone' => 'Europe/Moscow', 'urgent_surcharge_kopecks' => 20000,
        ]);
        $this->shopId = (int) $this->connection->lastInsertId();
        $this->connection->executeStatement(
            'INSERT INTO delivery_tariffs (shop_id, district_code, base_price_kopecks, free_from_kopecks) SELECT ?, district_code, base_price_kopecks, free_from_kopecks FROM delivery_tariffs WHERE shop_id = 1',
            [$this->shopId],
        );
        $this->importer = TestData::importer($this->connection);
    }

    protected function tearDown(): void
    {
        // These tests commit real transactions; remove only their own isolated shop.
        $this->connection->delete('orders', ['shop_id' => $this->shopId]);
        $this->connection->delete('delivery_tariffs', ['shop_id' => $this->shopId]);
        $this->connection->delete('shops', ['id' => $this->shopId]);
        $this->connection->close();
    }

    public function testFixtureAndRepeatedImport(): void
    {
        $first = $this->importer->import($this->shopId, TestData::fixture());
        self::assertSame(['created' => 7, 'updated' => 0, 'unchanged' => 0, 'duplicate' => 1, 'rejected' => 3], $first['summary']);
        self::assertSame(['unknown_district', 'invalid_phone', 'unknown_status'],
            array_column(array_column(array_filter($first['results'], static fn (array $r): bool => $r['outcome'] === 'rejected'), 'reason'), 'code'));
        self::assertSame('duplicate_in_batch', $first['results'][9]['reason']['code']);
        $second = $this->importer->import($this->shopId, TestData::fixture());
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 7, 'duplicate' => 1, 'rejected' => 3], $second['summary']);
        self::assertSame(7, $this->orderCount());
        self::assertSame(7, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.shop_id = ?', [$this->shopId]));
        $expected = [
            'MP-1001' => [320000, 30000, 350000, 0], 'MP-1002' => [500000, 60000, 560000, 0],
            'MP-1003' => [640000, 0, 640000, 0], 'MP-1004' => [150000, 0, 150000, 0],
            'MP-1005' => [280000, 40000, 320000, 0], 'MP-1009' => [360000, 70000, 430000, 1],
            'MP-1010' => [200000, 30000, 230000, 0],
        ];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM orders WHERE shop_id = ?', [$this->shopId]) as $row) {
            self::assertSame($expected[$row['marketplace_id']], array_map('intval', [
                $row['items_total_kopecks'], $row['delivery_total_kopecks'], $row['grand_total_kopecks'], $row['needs_review'],
            ]));
        }
    }

    public function testOnlyStatusChangesAndFinalStatusCannotChange(): void
    {
        $order = TestData::order();
        $this->importer->import($this->shopId, [$order]);
        $before = $this->connection->fetchAssociative('SELECT * FROM orders WHERE shop_id = ?', [$this->shopId]);
        $changed = $order;
        $changed['status'] = 'ACCEPTED';
        $changed['created_at'] = '2026-10-11T15:00:00+03:00';
        $changed['customer']['name'] = 'Другое имя';
        $changed['items'][0]['price'] = 2500;
        $changed['total'] = 2500;
        $result = $this->importer->import($this->shopId, [$changed]);
        self::assertSame('updated', $result['results'][0]['outcome']);
        self::assertSame(150000, $result['results'][0]['order']['items_total_kopecks']);
        $after = $this->connection->fetchAssociative('SELECT * FROM orders WHERE shop_id = ?', [$this->shopId]);
        self::assertSame('accepted', $after['status']);
        foreach (array_diff(array_keys($before), ['status', 'updated_at']) as $field) {
            self::assertSame($before[$field], $after[$field], $field.' unexpectedly changed');
        }
        self::assertSame(150000, (int) $this->connection->fetchOne('SELECT unit_price_kopecks FROM order_items WHERE order_id = ?', [$before['id']]));
        foreach (['DONE', 'CANCELED', 'NEW'] as $status) {
            $order['status'] = $status;
            $result = $this->importer->import($this->shopId, [$order]);
        }
        self::assertSame('unchanged', $result['results'][0]['outcome']);
        self::assertSame('final_status_preserved', $result['results'][0]['reason']['code']);
        self::assertSame('delivered', $result['results'][0]['order']['status']);
    }

    public function testDuplicateInBatchDoesNotApplyNewStatusEvenWhenFirstWasRejected(): void
    {
        $first = TestData::order();
        $second = array_replace($first, ['status' => 'DONE']);
        $result = $this->importer->import($this->shopId, [$first, $second]);
        self::assertSame('duplicate', $result['results'][1]['outcome']);
        self::assertSame('new', $this->connection->fetchOne('SELECT status FROM orders WHERE shop_id = ?', [$this->shopId]));
        $first['id'] = $second['id'] = 'MP-BAD-FIRST';
        $first['customer']['phone'] = '123';
        $result = $this->importer->import($this->shopId, [$first, $second]);
        self::assertSame(['rejected', 'duplicate'], array_column($result['results'], 'outcome'));
        self::assertSame(1, $this->orderCount());
    }

    public function testCancelledStatusIsAlsoFinal(): void
    {
        $order = array_replace(TestData::order(), ['status' => 'CANCELED']);
        $this->importer->import($this->shopId, [$order]);
        $order['status'] = 'DONE';
        $result = $this->importer->import($this->shopId, [$order]);
        self::assertSame('unchanged', $result['results'][0]['outcome']);
        self::assertSame('cancelled', $result['results'][0]['order']['status']);
        self::assertSame('final_status_preserved', $result['results'][0]['reason']['code']);
    }

    public function testInvalidRecordDoesNotPreventFollowingValidOrder(): void
    {
        $result = $this->importer->import($this->shopId, [null, [], ['id' => 'BAD'], TestData::order()]);
        self::assertSame(3, $result['summary']['rejected']);
        self::assertSame(1, $result['summary']['created']);
        self::assertSame(1, $this->orderCount());
    }

    public function testPersistenceErrorRollsBackWholeOrderAndBatchContinues(): void
    {
        // Force a storage failure after the order INSERT to check atomicity, not just validation.
        $this->connection->executeStatement(<<<'SQL'
            CREATE TEMPORARY TABLE order_items (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT, position INT,
                sku VARCHAR(128), name VARCHAR(255), quantity INT, unit_price_kopecks BIGINT,
                CONSTRAINT test_item_price CHECK (unit_price_kopecks < 200000)
            )
            SQL);
        try {
            $bad = TestData::order();
            $bad['id'] = 'ROLLBACK';
            $bad['items'][0]['price'] = 2500;
            $bad['total'] = 2500;
            $result = $this->importer->import($this->shopId, [$bad, TestData::order()]);
            self::assertSame(['rejected', 'created'], array_column($result['results'], 'outcome'));
            self::assertSame('storage_error', $result['results'][0]['reason']['code']);
            self::assertSame(1, $this->orderCount());
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM orders WHERE shop_id = ? AND marketplace_id = 'ROLLBACK'", [$this->shopId]));
        } finally {
            $this->connection->executeStatement('DROP TEMPORARY TABLE order_items');
        }
    }

    public function testConcurrentImportsCreateExactlySevenOrders(): void
    {
        $results = $this->concurrent();
        self::assertSame(7, array_sum(array_column(array_column($results, 'summary'), 'created')));
        self::assertSame(7, array_sum(array_column(array_column($results, 'summary'), 'unchanged')));
        foreach ($results as $result) {
            self::assertSame(3, $result['summary']['rejected']);
            self::assertSame(1, $result['summary']['duplicate']);
            self::assertNotContains('storage_error', array_column(array_column($result['results'], 'reason'), 'code'));
        }
        self::assertSame(7, $this->orderCount());
        self::assertSame(7, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.shop_id = ?', [$this->shopId]));
    }

    public function testConcurrentStatusUpdatesPreserveFinalStatus(): void
    {
        $this->importer->import($this->shopId, [TestData::order()]);
        $results = $this->concurrent(['DONE', 'ACCEPTED']);
        foreach ($results as $result) {
            self::assertSame(0, $result['summary']['rejected']);
        }
        self::assertSame('delivered', $this->connection->fetchOne('SELECT status FROM orders WHERE shop_id = ?', [$this->shopId]));
    }

    public function testHttpContractForFixtureAndMalformedRequests(): void
    {
        [$status, $body] = $this->request(json_encode(['orders' => TestData::fixture()], JSON_THROW_ON_ERROR));
        self::assertSame(200, $status);
        self::assertSame(11, count($body['results']));
        self::assertSame('unknown_district', $body['results'][5]['reason']['code']);
        self::assertSame(7, $body['summary']['created']);
        self::assertSame(400, $this->request('{broken')[0]);
        self::assertSame(400, $this->request('{"orders":{}}')[0]);
        self::assertSame(400, $this->request('{"orders":['.implode(',', array_fill(0, 1001, '{}')).']}')[0]);
        self::assertSame(415, $this->request('{"orders":[]}', 'text/plain')[0]);
        self::assertSame(413, $this->request(str_repeat(' ', 1_000_001))[0]);
        self::assertSame(404, $this->request('{"orders":[]}', 'application/json', 0)[0]);
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 0, 'duplicate' => 0, 'rejected' => 0], $this->request('{"orders":[]}')[1]['summary']);
    }

    private function orderCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM orders WHERE shop_id = ?', [$this->shopId]);
    }

    private function request(string $json, string $contentType = 'application/json', ?int $shop = null): array
    {
        $kernel = new Kernel('dev', true);
        $request = Request::create('/api/shops/'.($shop ?? $this->shopId).'/orders/import', 'POST', server: ['CONTENT_TYPE' => $contentType], content: $json);
        try {
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            return [$response->getStatusCode(), json_decode($response->getContent(), true, 128, JSON_THROW_ON_ERROR)];
        } finally {
            $kernel->shutdown();
        }
    }

    private function concurrent(?array $statuses = null): array
    {
        $barrier = sys_get_temp_dir().'/growth-import-'.bin2hex(random_bytes(8));
        $workers = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $command = [PHP_BINARY, dirname(__DIR__).'/Support/import-worker.php', (string) $this->shopId, $barrier];
                if ($statuses !== null) {
                    $command[] = $statuses[$i];
                }
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            touch($barrier);
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $stderr);
                $results[] = json_decode($stdout, true, 128, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }
    }
}
