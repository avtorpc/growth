<?php

declare(strict_types=1);

namespace App\Import;

final class OrderValidator
{
    public const MAX_MONEY = 1_000_000_000_000;
    private const STATUSES = [
        'NEW' => 'new', 'ACCEPTED' => 'accepted', 'IN_DELIVERY' => 'delivering',
        'DONE' => 'delivered', 'CANCELED' => 'cancelled',
    ];

    public function identifier(mixed $value): string
    {
        return $this->text($value, 'id', 128);
    }

    /** Returns database-ready values; validation never writes to the database. */
    public function validate(mixed $raw, string $timezone): array
    {
        if (!is_array($raw)) {
            throw new OrderRejected('invalid_order', 'Заказ должен быть объектом.');
        }
        $id = $this->identifier($raw['id'] ?? null);
        $externalStatus = $this->text($raw['status'] ?? null, 'status', 32);
        $status = self::STATUSES[$externalStatus] ?? throw new OrderRejected('unknown_status', 'Неизвестный статус заказа.');
        $customer = $this->object($raw['customer'] ?? null, 'customer');
        $name = $this->text($customer['name'] ?? null, 'customer.name', 255);
        $phone = $this->phone($customer['phone'] ?? null);
        $created = $this->createdAt($raw['created_at'] ?? null);
        $items = $raw['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || $items === [] || count($items) > 1000) {
            throw new OrderRejected('invalid_items', 'Нужен список от 1 до 1000 позиций.');
        }
        $normalizedItems = [];
        $itemsTotal = 0;
        foreach ($items as $index => $item) {
            $item = $this->object($item, 'items.'.($index + 1));
            $qty = $item['qty'] ?? null;
            if (!is_int($qty) || $qty < 1 || $qty > 1_000_000) {
                throw new OrderRejected('invalid_quantity', 'Количество должно быть целым числом от 1 до 1000000.');
            }
            $price = $this->money($item['price'] ?? null, 'items.price');
            if ($price > intdiv(self::MAX_MONEY - $itemsTotal, $qty)) {
                throw new OrderRejected('invalid_money', 'Сумма позиций превышает допустимый предел.');
            }
            $itemsTotal += $qty * $price;
            $normalizedItems[] = [
                'position' => $index + 1,
                'sku' => isset($item['sku']) ? $this->text($item['sku'], 'items.sku', 128) : null,
                'name' => $this->text($item['name'] ?? null, 'items.name', 255),
                'quantity' => $qty, 'unit_price_kopecks' => $price,
            ];
        }
        $delivery = $this->object($raw['delivery'] ?? null, 'delivery');
        $type = $delivery['type'] ?? null;
        if (!in_array($type, ['pickup', 'delivery'], true)) {
            throw new OrderRejected('invalid_delivery_type', 'Способ получения должен быть pickup или delivery.');
        }
        $district = $address = $from = $to = null;
        if ($type === 'delivery') {
            $district = $this->text($delivery['district'] ?? null, 'delivery.district', 64);
            if (!preg_match('/^[a-z0-9_-]+$/D', $district)) {
                throw new OrderRejected('invalid_district', 'Некорректный код района.');
            }
            $address = $this->text($delivery['address'] ?? null, 'delivery.address', 1000);
            $from = $this->deliveryMoment($delivery['date'] ?? null, $delivery['time_from'] ?? null, $timezone);
            $to = $this->deliveryMoment($delivery['date'] ?? null, $delivery['time_to'] ?? null, $timezone);
            if ($to <= $from) {
                throw new OrderRejected('invalid_interval', 'Конец интервала должен быть позже начала.');
            }
            if ($from < $created) {
                throw new OrderRejected('delivery_before_creation', 'Доставка не может начинаться раньше создания заказа.');
            }
        }
        return [
            'order' => [
                'marketplace_id' => $id, 'status' => $status,
                'customer_name' => $name, 'customer_phone' => $phone,
                'marketplace_created_at' => $created->format('Y-m-d H:i:s.u'),
                'delivery_type' => $type, 'district_code' => $district, 'delivery_address' => $address,
                'delivery_from' => $from?->format('Y-m-d H:i:s.u'),
                'delivery_to' => $to?->format('Y-m-d H:i:s.u'), 'timezone' => $timezone,
                'items_total_kopecks' => $itemsTotal,
                'marketplace_total_kopecks' => $this->money($raw['total'] ?? null, 'total'),
            ],
            'items' => $normalizedItems,
            'delivery_delay_seconds' => $from === null ? null : $from->getTimestamp() - $created->getTimestamp(),
        ];
    }

    public function money(mixed $value, string $field): int
    {
        $decimal = is_int($value) || is_float($value)
            ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION) : $value;
        if (!is_string($decimal) || !preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?$/D', $decimal, $parts)) {
            throw new OrderRejected('invalid_money', 'Поле '.$field.': нужна неотрицательная сумма в рублях с точностью до копейки.');
        }
        $kopecks = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($kopecks > self::MAX_MONEY) {
            throw new OrderRejected('invalid_money', 'Поле '.$field.': сумма превышает допустимый предел.');
        }
        return $kopecks;
    }

    private function text(mixed $value, string $field, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new OrderRejected('invalid_field', 'Поле '.$field.' обязательно: строка длиной до '.$max.' символов.');
        }
        return trim($value);
    }

    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new OrderRejected('invalid_field', 'Поле '.$field.' должно быть объектом.');
        }
        return $value;
    }

    private function phone(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 128) {
            throw new OrderRejected('invalid_phone', 'Некорректный телефон покупателя.');
        }
        $compact = preg_replace('/[\s()\-]/u', '', $value);
        if ($compact === null || !preg_match('/^(?:\+7|7|8)([0-9]{10})$/D', $compact, $parts)) {
            throw new OrderRejected('invalid_phone', 'Телефон невозможно привести к формату +7XXXXXXXXXX.');
        }
        return '+7'.$parts[1];
    }

    private function createdAt(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value)) {
            throw new OrderRejected('invalid_created_at', 'created_at должен содержать дату, время и часовой пояс.');
        }
        $value = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        if ($date === false || \DateTimeImmutable::getLastErrors() !== false || $date->format('Y-m-d\TH:i:sP') !== $value || (int) $date->format('Y') < 1000) {
            throw new OrderRejected('invalid_created_at', 'Некорректная дата создания заказа.');
        }
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    private function deliveryMoment(mixed $date, mixed $time, string $timezone): \DateTimeImmutable
    {
        if (!is_string($date) || !is_string($time) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !preg_match('/^\d{2}:\d{2}$/D', $time)) {
            throw new OrderRejected('invalid_interval', 'Нужны дата доставки YYYY-MM-DD и время HH:MM.');
        }
        $value = $date.' '.$time;
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new \DateTimeZone($timezone));
        if ($moment === false || \DateTimeImmutable::getLastErrors() !== false || $moment->format('Y-m-d H:i') !== $value || (int) $moment->format('Y') < 1000) {
            throw new OrderRejected('invalid_interval', 'Некорректная дата или время доставки.');
        }
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }
}
