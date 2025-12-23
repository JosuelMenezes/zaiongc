<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->string('method'); // cash|pix|card|voucher
            $table->decimal('amount', 12, 2);

            $table->string('status')->default('confirmed'); // confirmed|reversed
            $table->timestamp('paid_at')->useCurrent();

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['account_id', 'location_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
