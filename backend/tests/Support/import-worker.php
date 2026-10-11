<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Tests\Support\TestData;

// A filesystem barrier starts two independent PHP processes at the same time.
$deadline = microtime(true) + 10;
while (!file_exists($argv[2])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency-test barrier timed out.');
    }
    usleep(1000);
}
$connection = TestData::connection();
$orders = isset($argv[3])
    ? [array_replace(TestData::order(), ['status' => $argv[3]])]
    : TestData::fixture();
echo json_encode(TestData::importer($connection)->import((int) $argv[1], $orders), JSON_THROW_ON_ERROR);
$connection->close();
