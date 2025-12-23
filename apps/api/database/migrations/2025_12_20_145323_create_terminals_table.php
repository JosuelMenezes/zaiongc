<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('location_id');

            $table->string('name', 60);
            $table->string('code', 30)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['account_id', 'location_id']);
            $table->unique(['account_id', 'location_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};
