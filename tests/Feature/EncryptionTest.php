<?php

declare(strict_types=1);

use Crumbls\Fanout\Models\FanoutDelivery;
use Crumbls\Fanout\Models\FanoutEvent;
use Illuminate\Support\Facades\DB;

it('stores the event payload encrypted at rest', function (): void {
    $payload = ['type' => 'invoice.created', 'secret_token' => 'shh-very-secret-12345'];

    $event = new FanoutEvent;
    $event->forceFill([
        'profile'     => 'p',
        'event_type'  => 'invoice.created',
        'payload'     => $payload,
        'received_at' => now(),
    ])->save();

    // Read the row directly without going through the Eloquent cast layer.
    $raw = DB::table('fanout_events')->where('id', $event->getKey())->first();

    expect($raw->payload)->not->toBeNull();
    expect($raw->payload)->not->toContain('shh-very-secret-12345');
    expect($raw->payload)->not->toContain('"type":"invoice.created"');

    // Reading via Eloquent decrypts transparently.
    $reloaded = FanoutEvent::query()->find($event->getKey());
    expect($reloaded->payload)->toBe($payload);
});

it('stores the event headers encrypted at rest', function (): void {
    $headers = ['authorization' => ['Bearer never-store-me-plain']];

    $event = new FanoutEvent;
    $event->forceFill([
        'profile'     => 'p',
        'received_at' => now(),
        'headers'     => $headers,
    ])->save();

    $raw = DB::table('fanout_events')->where('id', $event->getKey())->first();

    expect($raw->headers)->not->toContain('never-store-me-plain');

    expect(FanoutEvent::query()->find($event->getKey())->headers)->toBe($headers);
});

it('stores the delivery response body encrypted at rest', function (): void {
    $event = new FanoutEvent;
    $event->forceFill([
        'profile'     => 'p',
        'received_at' => now(),
    ])->save();

    $delivery = new FanoutDelivery;
    $delivery->forceFill([
        'event_id'           => $event->getKey(),
        'endpoint_name'      => 'staging',
        'status'             => FanoutDelivery::STATUS_SUCCEEDED,
        'last_response_body' => 'sensitive-error-trace-12345',
    ])->save();

    $raw = DB::table('fanout_deliveries')->where('id', $delivery->getKey())->first();

    expect($raw->last_response_body)->not->toContain('sensitive-error-trace-12345');

    expect(FanoutDelivery::query()->find($delivery->getKey())->last_response_body)
        ->toBe('sensitive-error-trace-12345');
});
