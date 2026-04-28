<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FanoutEvent extends Model
{
    use HasUuids;

    protected $table = 'fanout_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_test'      => 'boolean',
            'headers'      => 'encrypted:array',
            'payload'      => 'encrypted:array',
            'received_at'  => 'datetime',
            'purgeable_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(
            related: app(\Crumbls\Fanout\Fanout::class)->deliveryModel(),
            foreignKey: 'event_id',
        );
    }

    public function isTest(): bool
    {
        return (bool) $this->is_test;
    }
}
