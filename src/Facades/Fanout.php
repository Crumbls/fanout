<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Facades;

use Crumbls\Fanout\Fanout as FanoutManager;
use Crumbls\Fanout\Models\FanoutEvent;
use Illuminate\Support\Facades\Facade;

/**
 * @method static FanoutEvent dispatch(string $profile, array $payload, array $headers = [])
 * @method static void replay(FanoutEvent|string $event, ?string $endpoint = null)
 * @method static int  replayFailed(?string $profile = null, ?string $endpoint = null)
 * @method static void extendValidator(string $name, \Closure $factory)
 * @method static void extendSigner(string $name, \Closure $factory)
 * @method static string eventModel()
 * @method static string deliveryModel()
 *
 * @see FanoutManager
 */
class Fanout extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FanoutManager::class;
    }
}
