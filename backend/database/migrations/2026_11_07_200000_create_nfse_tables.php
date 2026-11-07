<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recepção de NFS-e pelo Ambiente de Dados Nacional (ADN).
     *
     * - `nfse_nsus`: cursor NSU do ADN por unidade (separado do `nsus` da
     *   NF-e, que é outra fila, de outro órgão).
     * - `nfses`: uma linha por NFS-e POR UNIDADE (mesma regra da NF-e desde
     *   2.3.0). `papel` diz se a unidade é tomadora, prestadora ou
     *   intermediária: a fila do ADN mistura os três.
     * - `nfse_eventos`: cancelamentos, manifestações etc. O evento pode chegar
     *   antes da nota, por isso `nfse_id` é opcional e a ligação é pela chave.
     * - `sync_runs.tipo`: separa as execuções de NF-e e de NFS-e na tela.
     */
    public function up(): void
    {
        Schema::create('nfse_nsus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('unidade_id')->unique()->constrained('unidades')->cascadeOnDelete();
            $table->unsignedBigInteger('ultimo_nsu')->default(0);
            $table->dateTime('dh_resp')->nullable();
            $table->dateTime('historico_concluido_em')->nullable();
            $table->timestamps();
        });

        Schema::create('nfses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->cascadeOnDelete();
            $table->string('chave', 50);
            $table->unsignedBigInteger('nsu')->nullable();
            $table->string('papel', 16);
            $table->string('numero', 20)->nullable();
            $table->string('serie_dps', 10)->nullable();
            $table->string('numero_dps', 20)->nullable();
            $table->string('municipio_emissor_codigo', 7)->nullable();
            $table->string('municipio_emissor_nome')->nullable();
            $table->string('municipio_incidencia_nome')->nullable();
            $table->string('prestador_documento', 40)->nullable();
            $table->string('prestador_nome')->nullable();
            $table->string('tomador_documento', 40)->nullable();
            $table->string('tomador_nome')->nullable();
            $table->string('intermediario_documento', 40)->nullable();
            $table->string('intermediario_nome')->nullable();
            $table->dateTime('data_emissao')->nullable();
            $table->dateTime('data_processamento')->nullable();
            $table->date('competencia')->nullable();
            $table->string('codigo_servico', 10)->nullable();
            $table->string('servico_nome')->nullable();
            $table->text('descricao_servico')->nullable();
            $table->decimal('valor_servico', 15, 2)->nullable();
            $table->decimal('valor_liquido', 15, 2)->nullable();
            $table->decimal('valor_iss', 15, 2)->nullable();
            $table->boolean('iss_retido')->default(false);
            $table->string('situacao', 16)->default('normal');
            $table->string('ambiente_gerador', 1)->nullable();
            $table->string('versao_leiaute', 8)->nullable();
            $table->string('xml_path')->nullable();
            $table->string('checksum')->nullable();
            $table->timestamps();

            $table->unique(['unidade_id', 'chave']);
            $table->index(['tenant_id', 'data_emissao']);
            $table->index(['unidade_id', 'data_emissao']);
            $table->index(['tenant_id', 'papel']);
            $table->index(['tenant_id', 'prestador_documento']);
            $table->index(['tenant_id', 'competencia']);
        });

        Schema::create('nfse_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->cascadeOnDelete();
            $table->foreignId('nfse_id')->nullable()->constrained('nfses')->nullOnDelete();
            $table->string('chave', 50);
            $table->unsignedBigInteger('nsu');
            $table->string('tipo_evento', 48);
            $table->string('codigo_evento', 6)->nullable();
            $table->string('descricao')->nullable();
            $table->string('motivo')->nullable();
            $table->dateTime('data_evento')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('checksum')->nullable();
            $table->timestamps();

            $table->unique(['unidade_id', 'nsu']);
            $table->index(['unidade_id', 'chave']);
        });

        Schema::table('sync_runs', function (Blueprint $table) {
            $table->string('tipo', 8)->default('nfe')->after('unidade_id');
            $table->index(['tenant_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'tipo']);
            $table->dropColumn('tipo');
        });

        Schema::dropIfExists('nfse_eventos');
        Schema::dropIfExists('nfses');
        Schema::dropIfExists('nfse_nsus');
    }
};
