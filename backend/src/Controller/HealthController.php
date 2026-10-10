<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(#[AutowireLocator([\PDO::class])] ContainerInterface $connections): JsonResponse
    {
        try {
            $connections->get(\PDO::class)->query('SELECT 1');
        } catch (\PDOException) {
            return new JsonResponse(['status' => 'error', 'database' => 'unavailable'], 503);
        }

        return new JsonResponse(['status' => 'ok', 'database' => 'ok']);
    }
}
