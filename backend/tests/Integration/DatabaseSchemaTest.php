<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseSchemaTest extends TestCase
{
    private Connection $connection;
    private int $shopId;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => getenv('DATABASE_HOST'),
            'port' => (int) getenv('DATABASE_PORT'),
            'dbname' => getenv('DATABASE_NAME'),
            'user' => getenv('DATABASE_USER'),
            'password' => getenv('DATABASE_PASSWORD'),
            'charset' => 'utf8mb4',
        ]);
        $this->connection->beginTransaction();
        $this->shopId = $this->createShop();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection->close();
    }

    public function testDuplicateMarketplaceIdInOneShopIsRejected(): void
    {
        $this->createOrder();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->createOrder();
    }

    public function testMarketplaceIdIsScopedToShopAndCaseSensitive(): void
    {
        $first = $this->createOrder();
        $otherShop = $this->createOrder(['shop_id' => $this->createShop()]);
        $otherCase = $this->createOrder(['marketplace_id' => 'mp-test']);
        self::assertNotSame($first, $otherShop);
        self::assertNotSame($first, $otherCase);
    }

    public function testOrderTotalsUseItemsAndKeepUrgentFeeWhenBaseIsFree(): void
    {
        $id = $this->createOrder([
            'delivery_type' => 'delivery',
            'district_code' => 'centre',
            'delivery_address' => 'Тестовый адрес',
            'delivery_from' => '2026-10-10 09:00:00',
            'delivery_to' => '2026-10-10 11:00:00',
            'items_total_kopecks' => 640000,
            'marketplace_total_kopecks' => 600000,
            'delivery_surcharge_kopecks' => 20000,
        ]);
        $row = $this->connection->fetchAssociative('SELECT * FROM orders WHERE id = ?', [$id]);
        self::assertSame(1, (int) $row['needs_review']);
        self::assertSame(20000, (int) $row['delivery_total_kopecks']);
        self::assertSame(660000, (int) $row['grand_total_kopecks']);
    }

    public function testPickupHasNoDeliveryFeeOrReviewWhenTotalsMatch(): void
    {
        $id = $this->createOrder();
        $row = $this->connection->fetchAssociative('SELECT * FROM orders WHERE id = ?', [$id]);
        self::assertSame(0, (int) $row['needs_review']);
        self::assertSame(0, (int) $row['delivery_total_kopecks']);
        self::assertSame(150000, (int) $row['grand_total_kopecks']);
    }

    public function testLineTotalHasExactKopecksAndDeletionDoesNotLeaveOrphans(): void
    {
        $id = $this->createOrder();
        $this->connection->insert('order_items', [
            'order_id' => $id, 'position' => 1, 'sku' => 'B-TEST',
            'name' => 'Тестовый букет', 'quantity' => 3, 'unit_price_kopecks' => 19999,
        ]);
        self::assertSame(59997, (int) $this->connection->fetchOne(
            'SELECT line_total_kopecks FROM order_items WHERE order_id = ?', [$id],
        ));
        $this->connection->delete('orders', ['id' => $id]);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM order_items WHERE order_id = ?', [$id],
        ));
    }

    public function testOrderCannotReferenceMissingShop(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->createOrder(['shop_id' => 0]);
    }

    #[DataProvider('invalidOrders')]
    public function testDatabaseRejectsInvalidOrder(array $overrides, string $constraint): void
    {
        try {
            $this->createOrder($overrides);
            self::fail('The database accepted invalid order data.');
        } catch (Exception $exception) {
            self::assertStringContainsString($constraint, $exception->getMessage());
        }
    }

    public static function invalidOrders(): iterable
    {
        yield 'unknown status' => [['status' => 'packing'], 'chk_order_status'];
        yield 'invalid phone' => [['customer_phone' => '123'], 'chk_order_customer_phone'];
        yield 'empty name' => [['customer_name' => ' '], 'chk_order_customer_name'];
        yield 'empty marketplace id' => [['marketplace_id' => ' '], 'chk_order_marketplace_id'];
        yield 'negative money' => [['items_total_kopecks' => -1], 'chk_order_money'];
        yield 'pickup fee' => [['delivery_base_kopecks' => 1], 'chk_order_delivery'];
        yield 'incomplete delivery' => [['delivery_type' => 'delivery'], 'chk_order_delivery'];
        yield 'reversed interval' => [[
            'delivery_type' => 'delivery', 'district_code' => 'centre', 'delivery_address' => 'Адрес',
            'delivery_from' => '2026-10-10 11:00:00', 'delivery_to' => '2026-10-10 09:00:00',
        ], 'chk_order_delivery'];
    }

    public function testZeroItemQuantityIsRejected(): void
    {
        try {
            $this->connection->insert('order_items', [
                'order_id' => $this->createOrder(), 'position' => 1, 'name' => 'Букет',
                'quantity' => 0, 'unit_price_kopecks' => 10000,
            ]);
            self::fail('The database accepted zero quantity.');
        } catch (Exception $exception) {
            self::assertStringContainsString('chk_item_quantity', $exception->getMessage());
        }
    }

    public function testSeedMatchesAssignmentAndDatabaseSessionUsesUtc(): void
    {
        $shop = $this->connection->fetchAssociative("SELECT * FROM shops WHERE code = 'demo'");
        self::assertSame(1, (int) $shop['id']);
        self::assertSame('Europe/Moscow', $shop['timezone']);
        self::assertSame(20000, (int) $shop['urgent_surcharge_kopecks']);
        $tariffs = $this->connection->fetchAllAssociative(
            'SELECT district_code, base_price_kopecks, free_from_kopecks FROM delivery_tariffs WHERE shop_id = 1 ORDER BY district_code',
        );
        $normalized = array_map(static fn (array $row): array => [
            $row['district_code'], (int) $row['base_price_kopecks'],
            $row['free_from_kopecks'] === null ? null : (int) $row['free_from_kopecks'],
        ], $tariffs);
        self::assertSame([
            ['centre', 30000, 500000], ['north', 40000, 700000],
            ['south', 40000, 700000], ['suburb', 70000, null],
        ], $normalized);
        self::assertSame('+00:00', $this->connection->fetchOne('SELECT @@session.time_zone'));
    }

    private function createShop(): int
    {
        $this->connection->insert('shops', [
            'code' => 'test-'.bin2hex(random_bytes(8)), 'name' => 'Магазин для проверки',
            'timezone' => 'Europe/Moscow', 'urgent_surcharge_kopecks' => 20000,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    private function createOrder(array $overrides = []): int
    {
        $this->connection->insert('orders', array_replace([
            'shop_id' => $this->shopId, 'marketplace_id' => 'MP-TEST', 'status' => 'new',
            'customer_name' => 'Тестовый покупатель', 'customer_phone' => '+79001234567',
            'marketplace_created_at' => '2026-10-10 08:00:00', 'delivery_type' => 'pickup',
            'timezone' => 'Europe/Moscow', 'items_total_kopecks' => 150000,
            'marketplace_total_kopecks' => 150000, 'delivery_base_kopecks' => 0,
            'delivery_surcharge_kopecks' => 0,
        ], $overrides));
        return (int) $this->connection->lastInsertId();
    }
}
