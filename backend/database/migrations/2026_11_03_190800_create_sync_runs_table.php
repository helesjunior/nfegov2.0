<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoria de cada execução de sincronização por unidade.
     *
     * Serve para observabilidade e para nunca falhar em silêncio: registra o
     * que foi consumido (NSU inicial/final), quantos documentos vieram, o
     * status e, em caso de erro, a mensagem (ex.: certificado vencido).
     */
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->cascadeOnDelete();
            $table->dateTime('iniciado_em')->nullable();
            $table->dateTime('finalizado_em')->nullable();
            $table->unsignedBigInteger('nsu_inicial')->default(0);
            $table->unsignedBigInteger('nsu_final')->default(0);
            $table->unsignedInteger('docs_count')->default(0);
            $table->string('status', 16)->default('em_andamento');
            $table->text('erro')->nullable();
            $table->timestamps();

            $table->index(['unidade_id', 'iniciado_em']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};