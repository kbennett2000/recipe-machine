<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('referenced_slug');
            $table->foreignId('resolved_recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->string('source'); // 'frontmatter' | 'inline'
            $table->timestamps();

            $table->index('referenced_slug');
            $table->index('resolved_recipe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_references');
    }
};
