<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Testing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One captured request inside the in-package webhook sink. Only used when
 * config('fanout.testing.sink_enabled') is true.
 */
class FanoutTestCapture extends Model
{
    use HasUuids;

    protected $table = 'fanout_test_captures';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers'     => 'array',
            'query'       => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
