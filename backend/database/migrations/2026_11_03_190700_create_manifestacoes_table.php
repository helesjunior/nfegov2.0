<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico de manifestações enviadas à SEFAZ (ex.: ciência da operação).
     */
    public function up(): void
    {
        Schema::create('manifestacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->cascadeOnDelete();
            $table->foreignId('nfe_id')->nullable()->constrained('nfes')->nullOnDelete();
            $table->string('chave', 44);
            $table->string('tp_evento', 6);
            $table->unsignedSmallInteger('n_seq_evento')->default(1);
            $table->string('cstat', 3)->nullable();
            $table->string('x_motivo')->nullable();
            $table->dateTime('enviado_em')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'chave']);
            $table->index(['unidade_id', 'tp_evento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifestacoes');
    }
};