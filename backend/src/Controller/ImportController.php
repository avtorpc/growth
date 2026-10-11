<?php

declare(strict_types=1);

namespace App\Controller;

use App\Import\ImportService;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class ImportController
{
    #[Route('/api/shops/{shopId}/orders/import', name: 'orders_import', requirements: ['shopId' => '\d+'], methods: ['POST'])]
    public function __invoke(string $shopId, Request $request, ImportService $import, LoggerInterface $logger): JsonResponse
    {
        if (filter_var($shopId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return $this->error('shop_not_found', 'Магазин не найден.', 404);
        }
        if (strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0])) !== 'application/json') {
            return $this->error('unsupported_media_type', 'Нужен Content-Type: application/json.', 415);
        }
        $body = $request->getContent();
        if (strlen($body) > 1_000_000) {
            return $this->error('payload_too_large', 'Размер запроса не должен превышать 1000000 байт.', 413);
        }
        try {
            $payload = json_decode($body, false, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error('invalid_json', 'Некорректный JSON.', 400);
        }
        if (!$payload instanceof \stdClass || !isset($payload->orders) || !is_array($payload->orders) || count($payload->orders) > 1000) {
            return $this->error('invalid_payload', 'Нужен объект с массивом orders, максимум 1000 записей.', 400);
        }
        $orders = json_decode($body, true, 128, JSON_THROW_ON_ERROR)['orders'];
        try {
            return new JsonResponse($import->import((int) $shopId, $orders));
        } catch (\OutOfBoundsException) {
            return $this->error('shop_not_found', 'Магазин не найден.', 404);
        } catch (Exception $exception) {
            $logger->error('Import database unavailable.', ['exception' => $exception]);
            return $this->error('database_unavailable', 'База временно недоступна. Повторите запрос.', 503);
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
