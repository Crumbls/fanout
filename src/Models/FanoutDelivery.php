<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FanoutDelivery extends Model
{
    use HasUuids;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_IN_FLIGHT = 'in_flight';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_SKIPPED   = 'skipped';

    protected $table = 'fanout_deliveries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts'           => 'integer',
            'last_status_code'   => 'integer',
            'last_response_body' => 'encrypted',
            'request_headers'    => 'encrypted:array',
            'request_payload'    => 'encrypted:array',
            'next_attempt_at'    => 'datetime',
            'completed_at'       => 'datetime',
            'purgeable_at'       => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(
            related: app(\Crumbls\Fanout\Fanout::class)->eventModel(),
            foreignKey: 'event_id',
        );
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_SKIPPED], true);
    }
}
