<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('category');
            $table->string('servings')->nullable();
            $table->integer('yields')->nullable();
            $table->string('prep_time')->nullable();
            $table->string('cook_time')->nullable();
            $table->string('total_time')->nullable();
            $table->string('oven_temp')->nullable();
            $table->string('difficulty')->nullable();
            $table->string('libation')->nullable();
            $table->text('libation_prose')->nullable();
            $table->text('notes')->nullable();
            $table->string('source')->nullable();
            $table->string('source_path');
            $table->timestamp('source_mtime')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->json('parse_warnings')->nullable();
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
