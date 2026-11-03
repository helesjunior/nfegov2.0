<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico de certificados e-CNPJ por unidade.
     *
     * O arquivo do PFX fica cifrado em disco (apenas o caminho é indexado) e
     * a senha é gravada cifrada no campo `senha_encrypted` (cast encrypted).
     * Apenas um certificado fica `ativo` por vez; os anteriores permanecem
     * para auditoria. Nenhum endpoint devolve o PFX ou a senha.
     */
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->cascadeOnDelete();
            $table->string('arquivo_path');
            $table->text('senha_encrypted');
            $table->string('cnpj', 14);
            $table->string('titular');
            $table->dateTime('valido_de')->nullable();
            $table->dateTime('valido_ate');
            $table->string('fingerprint')->nullable();
            $table->string('emissor')->nullable();
            $table->boolean('ativo')->default(true);
            $table->dateTime('substituido_em')->nullable();
            $table->timestamps();

            $table->index(['unidade_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};