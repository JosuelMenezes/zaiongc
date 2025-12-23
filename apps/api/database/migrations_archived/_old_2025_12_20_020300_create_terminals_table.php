<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();

            $table->string('name'); // PDV 01, Caixa Principal
            $table->string('device_code')->unique(); // código do dispositivo
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['account_id', 'location_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};
