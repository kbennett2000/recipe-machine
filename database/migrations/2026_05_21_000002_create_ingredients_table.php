<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->integer('position');
            $table->string('group_name')->nullable();
            $table->text('raw');
            $table->boolean('parsed');
            $table->decimal('amount', 10, 4)->nullable();
            $table->decimal('amount_high', 10, 4)->nullable();
            $table->string('unit')->nullable();
            $table->string('unit_class')->nullable();
            $table->string('ingredient')->nullable();
            $table->string('modifier')->nullable();
            $table->text('note')->nullable();
            $table->boolean('optional')->default(false);
            $table->timestamps();

            $table->index(['recipe_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
