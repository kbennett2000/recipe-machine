<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('method_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->integer('position');
            $table->text('content');
            $table->timestamps();

            $table->index(['recipe_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('method_steps');
    }
};
