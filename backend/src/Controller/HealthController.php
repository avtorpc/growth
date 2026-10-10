<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(Connection $connection): JsonResponse
    {
        try {
            $connection->fetchOne('SELECT 1');
        } catch (Exception) {
            return new JsonResponse(['status' => 'error', 'database' => 'unavailable'], 503);
        }

        return new JsonResponse(['status' => 'ok', 'database' => 'ok']);
    }
}
