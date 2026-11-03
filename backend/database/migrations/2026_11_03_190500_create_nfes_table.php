<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NF-e recebidas via distDFe.
     *
     * `estado` distingue o resumo (resNFe, sem itens/impostos completos) do
     * documento completo (procNFe/nfeProc). `xml_path` aponta para o arquivo
     * no disco (o XML não fica no banco) e `checksum` garante integridade.
     * `emitente_proprio` marca notas emitidas pelo próprio CNPJ da unidade.
     */
    public function up(): void
    {
        Schema::create('nfes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->cascadeOnDelete();
            $table->foreignId('nsu_id')->nullable()->constrained('nsus')->nullOnDelete();
            $table->foreignId('fornecedor_id')->nullable()->constrained('fornecedores')->nullOnDelete();

            $table->string('chave', 44);
            $table->string('numero')->nullable();
            $table->string('serie')->nullable();
            $table->string('modelo', 2)->default('55');
            $table->string('emitente_cnpj', 14)->nullable();
            $table->string('emitente_nome')->nullable();
            $table->string('dest_cnpj', 14)->nullable();
            $table->decimal('valor_total', 15, 2)->nullable();
            $table->string('natureza_operacao')->nullable();
            $table->dateTime('data_emissao')->nullable();
            $table->dateTime('data_saida_entrada')->nullable();
            $table->string('cstat', 3)->nullable();
            $table->string('manifestacao')->nullable();
            $table->string('estado', 16)->default('resumo');
            $table->boolean('emitente_proprio')->default(false);
            $table->string('xml_path')->nullable();
            $table->string('checksum')->nullable();
            $table->string('schema_name')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'chave']);
            $table->index(['tenant_id', 'data_emissao']);
            $table->index(['tenant_id', 'emitente_cnpj']);
            $table->index(['unidade_id', 'data_emissao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfes');
    }
};