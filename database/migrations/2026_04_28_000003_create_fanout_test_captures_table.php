<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fanout_test_captures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('sink_name')->index();
            $table->string('method', 16);

            // Captures are explicitly debug data — not encrypted. Never enable
            // the sink in production; the config flag defaults off precisely
            // because of this.
            $table->json('headers');
            $table->longText('payload')->nullable();
            $table->json('query')->nullable();

            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });
    }
};
