<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fanout_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->index();
            $table->string('endpoint_name')->index();
            $table->string('endpoint_environment')->nullable()->index();

            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();

            // Encrypted via the model layer.
            $table->text('last_response_body')->nullable();
            $table->text('last_error')->nullable();
            $table->text('request_headers')->nullable();
            $table->longText('request_payload')->nullable();

            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('purgeable_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('event_id')
                ->references('id')
                ->on('fanout_events')
                ->cascadeOnDelete();
        });
    }
};
