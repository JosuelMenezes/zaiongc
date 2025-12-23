<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();

            $table->string('name');              // Ex: Mesa 01, Balcão, Delivery
            $table->unsignedInteger('seats')->default(2);
            $table->string('status')->default('free'); // free|occupied|reserved|blocked
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['account_id', 'location_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
    }
};
