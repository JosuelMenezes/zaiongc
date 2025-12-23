<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (!Schema::hasColumn('shifts', 'expected_cash')) {
                $table->decimal('expected_cash', 12, 2)->nullable()->after('closing_cash');
            }

            if (!Schema::hasColumn('shifts', 'difference')) {
                $table->decimal('difference', 12, 2)->nullable()->after('expected_cash');
            }
        });
    }

    public function down(): void
    {
        // Não removemos aqui para evitar conflito com a migration anterior (191037)
        // que agora é a "fonte" real desses campos.
        // Deixe vazio mesmo.
    }
};
