<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\DeliveryCalculator;
use App\Import\OrderRejected;
use App\Import\OrderValidator;
use App\Tests\Support\TestData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderRulesTest extends TestCase
{
    #[DataProvider('phoneExamples')]
    public function testPhoneNormalization(string $input, string $expected): void
    {
        $order = TestData::order();
        $order['customer']['phone'] = $input;
        self::assertSame($expected, (new OrderValidator())->validate($order, 'Europe/Moscow')['order']['customer_phone']);
    }

    public static function phoneExamples(): iterable
    {
        yield ['+7 (900) 123-45-67', '+79001234567'];
        yield ['89001112233', '+79001112233'];
        yield ['8 900 444 33 22', '+79004443322'];
        yield ['79001234567', '+79001234567'];
    }

    #[DataProvider('moneyExamples')]
    public function testMoneyIsConvertedWithoutFloatArithmetic(mixed $input, int $kopecks): void
    {
        self::assertSame($kopecks, (new OrderValidator())->money($input, 'price'));
    }

    public static function moneyExamples(): iterable
    {
        yield [0, 0];
        yield [3200, 320000];
        yield [19.99, 1999];
        yield ['0.01', 1];
        yield ['12.3', 1230];
    }

    #[DataProvider('invalidMoney')]
    public function testInvalidMoneyIsRejected(mixed $input): void
    {
        $this->expectException(OrderRejected::class);
        (new OrderValidator())->money($input, 'price');
    }

    public static function invalidMoney(): iterable
    {
        yield [-1]; yield [1.001]; yield [true]; yield [null];
        yield ['1e3']; yield ['0.001']; yield ['10000000001'];
    }

    #[DataProvider('deliveryExamples')]
    public function testDeliveryBoundaries(string $time, int $price, int $base, int $surcharge): void
    {
        $validated = (new OrderValidator())->validate(TestData::deliveryOrder($time, $price), 'Europe/Moscow');
        $result = (new DeliveryCalculator())->calculate($validated,
            ['urgent_surcharge_kopecks' => 20000],
            ['centre' => ['base_price_kopecks' => 30000, 'free_from_kopecks' => 500000]],
        );
        self::assertSame(['delivery_base_kopecks' => $base, 'delivery_surcharge_kopecks' => $surcharge], $result);
    }

    public static function deliveryExamples(): iterable
    {
        yield 'one minute below 2 hours' => ['12:59', 4999, 30000, 20000];
        yield 'exactly 2 hours' => ['13:00', 4999, 30000, 0];
        yield 'inclusive free threshold' => ['13:00', 5000, 0, 0];
        yield 'free base retains urgency' => ['12:00', 5000, 0, 20000];
        yield 'immediate delivery is urgent' => ['11:00', 1500, 30000, 20000];
    }

    public function testUtcConversionAndHistoricalDates(): void
    {
        $validated = (new OrderValidator())->validate(TestData::deliveryOrder(), 'Europe/Moscow');
        self::assertSame('2026-10-10 08:00:00.000000', $validated['order']['marketplace_created_at']);
        self::assertSame('2026-10-10 09:00:00.000000', $validated['order']['delivery_from']);
        self::assertSame(3600, $validated['delivery_delay_seconds']);
    }

    public function testPickupDoesNotRequireAddressOrTariff(): void
    {
        $validated = (new OrderValidator())->validate(TestData::order(), 'Europe/Moscow');
        self::assertNull($validated['order']['delivery_from']);
        self::assertSame(['delivery_base_kopecks' => 0, 'delivery_surcharge_kopecks' => 0],
            (new DeliveryCalculator())->calculate($validated, ['urgent_surcharge_kopecks' => 20000], []));
    }

    #[DataProvider('invalidOrders')]
    public function testInvalidOrderHasSpecificReason(array $order, string $code): void
    {
        try {
            (new OrderValidator())->validate($order, 'Europe/Moscow');
            self::fail('Invalid order was accepted.');
        } catch (OrderRejected $exception) {
            self::assertSame($code, $exception->reasonCode);
        }
    }

    public static function invalidOrders(): iterable
    {
        $order = TestData::order();
        yield 'unknown status' => [array_replace($order, ['status' => 'PACKING']), 'unknown_status'];
        yield 'bad phone' => [array_replace_recursive($order, ['customer' => ['phone' => '123']]), 'invalid_phone'];
        yield 'empty items' => [array_replace($order, ['items' => []]), 'invalid_items'];
        yield 'fractional quantity' => [array_replace_recursive($order, ['items' => [['qty' => 1.5]]]), 'invalid_quantity'];
        yield 'negative price' => [array_replace_recursive($order, ['items' => [['price' => -1]]]), 'invalid_money'];
        yield 'overflowing item sum' => [array_replace_recursive($order, ['items' => [['price' => 10000000000, 'qty' => 2]]]), 'invalid_money'];
        yield 'unknown type' => [array_replace_recursive($order, ['delivery' => ['type' => 'courier']]), 'invalid_delivery_type'];
        yield 'bad date' => [array_replace($order, ['created_at' => '2026-02-30T11:00:00+03:00']), 'invalid_created_at'];
        yield 'missing timezone' => [array_replace($order, ['created_at' => '2026-10-10T11:00:00']), 'invalid_created_at'];
        yield 'before creation' => [TestData::deliveryOrder('10:59'), 'delivery_before_creation'];
        yield 'equal interval boundaries' => [TestData::deliveryOrder('16:00'), 'invalid_interval'];
        $badInterval = TestData::deliveryOrder();
        $badInterval['delivery']['time_from'] = '25:00';
        yield 'invalid clock time' => [$badInterval, 'invalid_interval'];
    }
}
