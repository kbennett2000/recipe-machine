<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_llm_cache', function (Blueprint $table) {
            $table->id();
            $table->text('raw_line');
            // 'hit' rows carry a parsed_payload; 'miss' rows are tombstones
            // (the LLM tried and returned nothing usable). Miss rows carry
            // expires_at so they can be re-attempted later.
            $table->string('status'); // 'hit' | 'miss'
            $table->json('parsed_payload')->nullable();
            $table->string('model_used')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // SQLite can't do a unique index on TEXT columns longer than its
            // page size; raw_line might be long. Use a sha1 hash column for
            // fast equality lookups instead.
            $table->string('raw_line_hash', 40)->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_llm_cache');
    }
};
