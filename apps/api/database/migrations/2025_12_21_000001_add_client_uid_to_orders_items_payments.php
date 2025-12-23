<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // orders
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'client_uid')) {
                $table->string('client_uid', 26)->nullable()->after('id');
            }
            $table->unique(['account_id', 'location_id', 'client_uid'], 'orders_tenant_client_uid_unique');
        });

        // order_items
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'client_uid')) {
                $table->string('client_uid', 26)->nullable()->after('id');
            }
            $table->unique(['account_id', 'location_id', 'client_uid'], 'order_items_tenant_client_uid_unique');
        });

        // payments
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'client_uid')) {
                $table->string('client_uid', 26)->nullable()->after('id');
            }
            $table->unique(['account_id', 'location_id', 'client_uid'], 'payments_tenant_client_uid_unique');
        });
    }

    public function down(): void
    {
        // Em SQLite, dropIndex/dropColumn pode variar conforme versão.
        // Se estiver em MySQL/Postgres, funciona normalmente.

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_tenant_client_uid_unique');
            $table->dropColumn('client_uid');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropUnique('order_items_tenant_client_uid_unique');
            $table->dropColumn('client_uid');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_tenant_client_uid_unique');
            $table->dropColumn('client_uid');
        });
    }
};
