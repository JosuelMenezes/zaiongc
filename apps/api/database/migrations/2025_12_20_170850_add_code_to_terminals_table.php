<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            if (!Schema::hasColumn('terminals', 'code')) {
                $table->string('code', 30)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            if (Schema::hasColumn('terminals', 'code')) {
                $table->dropColumn('code');
            }
        });
    }
};
