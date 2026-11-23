<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Illuminate\Http\JsonResponse;

final class ResourceController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    public function store(): JsonResponse
    {
        return new JsonResponse(['id' => SideEffects::record('resource.store')], 201);
    }

    public function show(string $resource): JsonResponse
    {
        return new JsonResponse(['id' => $resource]);
    }

    public function update(string $resource): JsonResponse
    {
        return new JsonResponse(['id' => $resource, 'updates' => SideEffects::record('resource.update')]);
    }

    public function destroy(string $resource): JsonResponse
    {
        return new JsonResponse(['deleted' => $resource, 'deletes' => SideEffects::record('resource.destroy')]);
    }
}
