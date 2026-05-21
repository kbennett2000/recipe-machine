<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // Phase 9: marks an ingredient whose structured fields came from
            // the LLM fallback rather than the rules-based parser. Used by
            // the detail page to surface a ✨ indicator next to the line so
            // the maintainer knows to spot-check it.
            $table->boolean('llm_parsed')->default(false)->after('parsed');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('llm_parsed');
        });
    }
};
