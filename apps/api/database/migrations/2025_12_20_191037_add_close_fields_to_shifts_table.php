<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Sua tabela já tem: status, closed_at, closed_by, closing_cash.
            // Então aqui adicionamos SOMENTE o que falta para o fechamento auditável.

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
        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'difference')) {
                $table->dropColumn('difference');
            }

            if (Schema::hasColumn('shifts', 'expected_cash')) {
                $table->dropColumn('expected_cash');
            }
        });
    }
};
