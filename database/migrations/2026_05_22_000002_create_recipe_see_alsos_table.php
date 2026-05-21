<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_see_alsos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->foreignId('related_recipe_id')->constrained('recipes')->cascadeOnDelete();
            // Jaccard-similarity-derived integer score (0..100). Higher is closer.
            $table->unsignedSmallInteger('score');
            $table->timestamps();

            $table->unique(['recipe_id', 'related_recipe_id']);
            // Lookups on the detail page hit (recipe_id, score DESC) — index both.
            $table->index(['recipe_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_see_alsos');
    }
};
