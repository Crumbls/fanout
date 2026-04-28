<?php

declare(strict_types=1);

use Crumbls\Fanout\Testing\FanoutTestCapture;

beforeEach(function (): void {
    config()->set('fanout.testing.sink_enabled', true);
    config()->set('fanout.testing.sink_prefix', 'fanout/_sink');

    // The service provider only registers sink routes when the flag is on
    // *at boot time*. Re-register them now that the flag is true.
    (new \Crumbls\Fanout\FanoutServiceProvider($this->app))->boot();
});

it('does not register sink routes when the flag is off', function (): void {
    config()->set('fanout.testing.sink_enabled', false);

    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn ($uri) => str_contains($uri, '_sink'));

    // Routes registered before this test from the beforeEach will still be
    // present, but a fresh boot with the flag off would not register them.
    // Verify the controller class respects the flag by clearing routes.
    \Illuminate\Support\Facades\Route::setRoutes(new \Illuminate\Routing\RouteCollection);

    (new \Crumbls\Fanout\FanoutServiceProvider($this->app))->boot();

    $routesAfter = collect(\Illuminate\Support\Facades\Route::getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn ($uri) => str_contains($uri, '_sink'));

    expect($routesAfter)->toBeEmpty();
});

it('captures a posted request to the sink', function (): void {
    $response = $this->postJson('/fanout/_sink/staging', [
        'type'    => 'invoice.paid',
        'payload' => 'hello',
    ], [
        'X-Fanout-Source' => 'production',
        'X-Custom'        => 'abc',
    ]);

    $response->assertStatus(200);
    expect($response->json('captured'))->toBeTrue();

    $capture = FanoutTestCapture::query()->first();
    expect($capture)->not->toBeNull();
    expect($capture->sink_name)->toBe('staging');
    expect($capture->method)->toBe('POST');
    expect($capture->payload)->toContain('invoice.paid');
    expect(strtolower($capture->headers['x-fanout-source'][0] ?? ''))->toBe('production');
});

it('lists recent captures via GET', function (): void {
    foreach (range(1, 3) as $i) {
        $this->postJson('/fanout/_sink/staging', ['n' => $i]);
    }

    $response = $this->getJson('/fanout/_sink/staging/captures');

    $response->assertStatus(200);
    expect($response->json('count'))->toBe(3);
    expect($response->json('data.0.sink_name'))->toBe('staging');
});

it('separates captures by sink name', function (): void {
    $this->postJson('/fanout/_sink/staging', ['x' => 1]);
    $this->postJson('/fanout/_sink/dev',     ['x' => 2]);
    $this->postJson('/fanout/_sink/dev',     ['x' => 3]);

    expect($this->getJson('/fanout/_sink/staging/captures')->json('count'))->toBe(1);
    expect($this->getJson('/fanout/_sink/dev/captures')->json('count'))->toBe(2);
});

it('clears captures via DELETE without touching other sinks', function (): void {
    $this->postJson('/fanout/_sink/staging', ['x' => 1]);
    $this->postJson('/fanout/_sink/staging', ['x' => 2]);
    $this->postJson('/fanout/_sink/dev',     ['x' => 3]);

    $response = $this->deleteJson('/fanout/_sink/staging');

    $response->assertStatus(200);
    expect($response->json('cleared'))->toBe(2);

    expect(FanoutTestCapture::query()->where('sink_name', 'staging')->count())->toBe(0);
    expect(FanoutTestCapture::query()->where('sink_name', 'dev')->count())->toBe(1);
});
