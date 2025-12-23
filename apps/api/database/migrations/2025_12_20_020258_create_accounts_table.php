<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // Nome do estabelecimento/empresa
            $table->string('slug')->unique();       // URL/identificador amigável
            $table->string('document')->nullable(); // CPF/CNPJ
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Controle SaaS (fase 2: Asaas, planos, etc.)
            $table->string('plan')->default('trial');
            $table->string('status')->default('active'); // active|suspended|canceled
            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
