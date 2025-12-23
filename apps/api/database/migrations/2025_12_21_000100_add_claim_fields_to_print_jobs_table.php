<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('available_at');
            $table->string('claimed_by')->nullable()->after('claimed_at');

            $table->index(['status', 'available_at']);
            $table->index(['claimed_by', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropIndex(['status', 'available_at']);
            $table->dropIndex(['claimed_by', 'claimed_at']);

            $table->dropColumn(['claimed_at', 'claimed_by']);
        });
    }
};
