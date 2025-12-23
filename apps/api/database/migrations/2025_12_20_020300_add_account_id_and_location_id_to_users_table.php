<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->after('account_id')->constrained('locations')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('remember_token');

            $table->index(['account_id', 'location_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'location_id', 'is_active']);
            $table->dropConstrainedForeignId('location_id');
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn('is_active');
        });
    }
};
