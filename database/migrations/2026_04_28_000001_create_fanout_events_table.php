<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fanout_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('profile')->index();
            $table->string('event_type')->nullable()->index();
            $table->string('schema_version')->nullable();
            $table->boolean('is_test')->default(false)->index();

            // Encrypted at the model layer; column types are sized for the
            // ciphertext envelope.
            $table->text('headers')->nullable();
            $table->longText('payload')->nullable();
            $table->string('signature', 1024)->nullable();

            $table->timestamp('received_at')->index();
            $table->timestamp('purgeable_at')->nullable()->index();
            $table->timestamps();
        });
    }
};
