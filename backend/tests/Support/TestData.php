<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Import\DeliveryCalculator;
use App\Import\ImportService;
use App\Import\OrderValidator;
use App\Repository\OrderRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;

final class TestData
{
    public static function connection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => getenv('DATABASE_HOST'),
            'port' => (int) getenv('DATABASE_PORT'), 'dbname' => getenv('DATABASE_NAME'),
            'user' => getenv('DATABASE_USER'), 'password' => getenv('DATABASE_PASSWORD'),
            'charset' => 'utf8mb4',
        ]);
    }

    public static function importer(Connection $connection): ImportService
    {
        return new ImportService(new OrderRepository($connection), new OrderValidator(), new DeliveryCalculator(), new NullLogger());
    }

    public static function order(): array
    {
        return [
            'id' => 'MP-TEST', 'status' => 'NEW', 'created_at' => '2026-10-10T11:00:00+03:00',
            'customer' => ['name' => 'Тестовый покупатель', 'phone' => '8 (900) 123-45-67'],
            'delivery' => ['type' => 'pickup'],
            'items' => [['sku' => 'B-TEST', 'name' => 'Букет', 'qty' => 1, 'price' => 1500]],
            'total' => 1500,
        ];
    }

    public static function deliveryOrder(string $timeFrom = '12:00', int $price = 1500): array
    {
        $order = self::order();
        $order['delivery'] = [
            'type' => 'delivery', 'district' => 'centre', 'address' => 'Тестовый адрес',
            'date' => '2026-10-10', 'time_from' => $timeFrom, 'time_to' => '16:00',
        ];
        $order['items'][0]['price'] = $price;
        $order['total'] = $price;
        return $order;
    }

    public static function fixture(): array
    {
        $path = file_exists('/fixtures/marketplace-orders.json')
            ? '/fixtures/marketplace-orders.json'
            : dirname(__DIR__, 3).'/frontend/src/assets/marketplace-orders.json';
        return json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR)['orders'];
    }
}
