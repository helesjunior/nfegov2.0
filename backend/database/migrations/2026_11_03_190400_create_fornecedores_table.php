<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Emitentes (fornecedores) normalizados por CNPJ.
     */
    public function up(): void
    {
        Schema::create('fornecedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('cnpj', 14);
            $table->string('ie')->nullable();
            $table->string('im')->nullable();
            $table->string('nome');
            $table->string('endereco')->nullable();
            $table->string('endereco_numero')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cep', 8)->nullable();
            $table->string('telefone')->nullable();
            $table->string('cnae')->nullable();
            $table->foreignId('estado_id')->nullable();
            $table->foreignId('municipio_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'cnpj']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fornecedores');
    }
};