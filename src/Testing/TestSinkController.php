<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Testing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-package catcher for end-to-end smoke tests. Stores incoming requests
 * to fanout_test_captures so a Postman/Newman collection (or a Pest test)
 * can poll them back. Only registered when sink_enabled is true.
 */
class TestSinkController
{
    public function capture(Request $request, string $name): JsonResponse
    {
        $capture = new FanoutTestCapture;
        $capture->forceFill([
            'sink_name'   => $name,
            'method'      => $request->getMethod(),
            'headers'     => $request->headers->all(),
            'payload'     => $request->getContent(),
            'query'       => $request->query->all(),
            'captured_at' => now(),
        ])->save();

        return response()->json([
            'captured'   => true,
            'capture_id' => $capture->getKey(),
            'sink_name'  => $name,
        ], 200);
    }

    public function index(Request $request, string $name): JsonResponse
    {
        $limit = min((int) $request->query('limit', 50), 200);

        $captures = FanoutTestCapture::query()
            ->where('sink_name', $name)
            ->orderByDesc('captured_at')
            ->limit($limit)
            ->get()
            ->map(fn (FanoutTestCapture $c) => [
                'id'          => $c->getKey(),
                'sink_name'   => $c->sink_name,
                'method'      => $c->method,
                'headers'     => $c->headers,
                'payload'     => $c->payload,
                'query'       => $c->query,
                'captured_at' => $c->captured_at?->toIso8601String(),
            ]);

        return response()->json([
            'sink_name' => $name,
            'count'     => $captures->count(),
            'data'      => $captures,
        ]);
    }

    public function clear(string $name): JsonResponse
    {
        $deleted = FanoutTestCapture::query()
            ->where('sink_name', $name)
            ->delete();

        return response()->json([
            'sink_name' => $name,
            'cleared'   => $deleted,
        ]);
    }
}
