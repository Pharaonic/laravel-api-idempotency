<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderController
{
    public function store(Request $request): JsonResponse
    {
        $id = SideEffects::record('store');
        $user = $request->user();

        $response = new JsonResponse([
            'id' => $id,
            'status' => 'created',
            'user' => $user === null ? null : $user->getAuthIdentifier(),
            'input' => $request->json()->all(),
        ], 201);

        $response->headers->set('Location', '/orders/'.$id);
        $response->headers->set('X-Debug-Token', 'private-'.$id);
        $response->headers->setCookie(Cookie::create('order_hint', (string) $id));

        return $response;
    }

    public function refund(Order $order): JsonResponse
    {
        SideEffects::record('refund');

        return new JsonResponse(['refunded' => $order->id, 'total' => $order->total], 201);
    }

    public function noContent(): Response
    {
        SideEffects::record('no-content');

        return new Response('', 204);
    }

    public function invalid(): JsonResponse
    {
        SideEffects::record('invalid');

        return new JsonResponse(['message' => 'The quantity is invalid.'], 422);
    }

    public function fail(): JsonResponse
    {
        SideEffects::record('fail');

        throw new RuntimeException('The payment provider timed out.');
    }

    public function html(): Response
    {
        SideEffects::record('html');

        return new Response('<p>created</p>', 200, ['Content-Type' => 'text/html']);
    }

    public function stream(): StreamedResponse
    {
        SideEffects::record('stream');

        return new StreamedResponse(function (): void {
            echo '{"streamed":true}';
        }, 200, ['Content-Type' => 'application/json']);
    }

    public function large(): JsonResponse
    {
        SideEffects::record('large');

        return new JsonResponse(['data' => str_repeat('x', 2048)], 200);
    }

    public function redirect(): Response
    {
        SideEffects::record('redirect');

        return new Response('', 303, ['Location' => '/orders/1']);
    }
}
