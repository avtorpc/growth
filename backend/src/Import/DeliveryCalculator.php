<?php

declare(strict_types=1);

namespace App\Import;

final class DeliveryCalculator
{
    public function calculate(array $validated, array $shop, array $tariffs): array
    {
        $order = $validated['order'];
        if ($order['delivery_type'] === 'pickup') {
            return ['delivery_base_kopecks' => 0, 'delivery_surcharge_kopecks' => 0];
        }
        $tariff = $tariffs[$order['district_code']] ?? throw new OrderRejected('unknown_district', 'Для района '.$order['district_code'].' нет тарифа доставки.');
        $base = $tariff['free_from_kopecks'] !== null && $order['items_total_kopecks'] >= (int) $tariff['free_from_kopecks']
            ? 0 : (int) $tariff['base_price_kopecks'];
        $surcharge = $validated['delivery_delay_seconds'] < 7200 ? (int) $shop['urgent_surcharge_kopecks'] : 0;
        if ($base < 0 || $surcharge < 0 || $base > OrderValidator::MAX_MONEY - $order['items_total_kopecks']
            || $surcharge > OrderValidator::MAX_MONEY - $order['items_total_kopecks'] - $base) {
            throw new OrderRejected('invalid_money', 'Итоговая сумма превышает допустимый предел.');
        }
        return ['delivery_base_kopecks' => $base, 'delivery_surcharge_kopecks' => $surcharge];
    }
}
