<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Itens de uma NF-e. Só são preenchidos quando o XML completo chega
     * (o resumo/resNFe não traz itens).
     */
    public function up(): void
    {
        Schema::create('nfe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('nfe_id')->constrained('nfes')->cascadeOnDelete();
            $table->unsignedInteger('sequencial');
            $table->string('descricao');
            $table->string('cfop', 4)->nullable();
            $table->string('unidade_medida')->nullable();
            $table->decimal('quantidade', 15, 4)->nullable();
            $table->decimal('valor_unitario', 15, 4)->nullable();
            $table->decimal('valor_total', 15, 2)->nullable();
            $table->string('unidade_medida_tributado')->nullable();
            $table->decimal('quantidade_tributado', 15, 4)->nullable();
            $table->decimal('valor_unitario_tributado', 15, 4)->nullable();
            $table->timestamps();

            $table->unique(['nfe_id', 'sequencial']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfe_items');
    }
};