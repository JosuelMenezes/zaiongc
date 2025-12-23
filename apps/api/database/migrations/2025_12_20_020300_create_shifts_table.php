<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('location_id');

            $table->unsignedBigInteger('terminal_id');
            $table->unsignedBigInteger('opened_by');
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->string('status', 20)->default('open'); // open | closed
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();

            $table->decimal('opening_cash', 12, 2)->default(0);

            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('counted_pix', 12, 2)->nullable();
            $table->decimal('counted_card', 12, 2)->nullable();
            $table->decimal('counted_voucher', 12, 2)->nullable();

            $table->timestamps();

            $table->index(['account_id', 'location_id']);
            $table->index(['terminal_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
