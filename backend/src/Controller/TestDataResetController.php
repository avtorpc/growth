<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TestDataResetRepository;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class TestDataResetController
{
    public function __construct(#[Autowire('%kernel.environment%')] private readonly string $environment) {}

    #[Route('/api/shops/{shopId}/test-data/reset', name: 'test_data_reset', requirements: ['shopId' => '\d+'], methods: ['POST'])]
    public function __invoke(string $shopId, Request $request, TestDataResetRepository $reset, LoggerInterface $logger): JsonResponse
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            return $this->error('reset_disabled', 'Очистка доступна только в тестовом окружении.', 404);
        }
        if (filter_var($shopId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return $this->error('shop_not_found', 'Магазин не найден.', 404);
        }
        if (strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0])) !== 'application/json') {
            return $this->error('unsupported_media_type', 'Нужен Content-Type: application/json.', 415);
        }
        try {
            $payload = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error('invalid_json', 'Некорректный JSON.', 400);
        }
        if (!is_array($payload) || ($payload['confirm'] ?? null) !== true) {
            return $this->error('confirmation_required', 'Нужно подтвердить удаление тестовых данных.', 400);
        }
        try {
            $reset->reset((int) $shopId);
            return new JsonResponse(['shop_id' => (int) $shopId, 'status' => 'cleared']);
        } catch (\OutOfBoundsException $exception) {
            return $this->error('shop_not_found', $exception->getMessage(), 404);
        } catch (\LogicException $exception) {
            return $this->error('import_in_progress', $exception->getMessage(), 409);
        } catch (Exception $exception) {
            $logger->error('Test data reset failed.', ['shop_id' => $shopId, 'exception' => $exception]);
            return $this->error('database_unavailable', 'Не удалось очистить базу. Повторите попытку.', 503);
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
