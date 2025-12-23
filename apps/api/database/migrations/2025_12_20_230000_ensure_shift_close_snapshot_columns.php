<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {

            // status
            if (!Schema::hasColumn('shifts', 'status')) {
                $table->string('status')->default('open');
            }

            // close fields
            if (!Schema::hasColumn('shifts', 'closed_at')) {
                $table->dateTime('closed_at')->nullable();
            }

            if (!Schema::hasColumn('shifts', 'closed_by')) {
                $table->unsignedBigInteger('closed_by')->nullable();
            }

            if (!Schema::hasColumn('shifts', 'closing_cash')) {
                $table->decimal('closing_cash', 10, 2)->nullable();
            }

            // snapshot fields
            if (!Schema::hasColumn('shifts', 'expected_cash')) {
                $table->decimal('expected_cash', 10, 2)->nullable();
            }

            if (!Schema::hasColumn('shifts', 'difference')) {
                $table->decimal('difference', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Não remover colunas em down para evitar perda de dados em rollback real de produção.
            // (Em projeto SaaS/PDV é melhor migrations sempre "forward-only" para essas colunas críticas.)
        });
    }
};
