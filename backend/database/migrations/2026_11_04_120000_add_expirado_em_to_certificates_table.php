<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado explícito de "certificado vencido".
     *
     * `expirado_em` é um carimbo (nullable) gravado quando o certificado
     * vence, para que o banco reflita o estado sem apagar informação. NÃO
     * substitui `ativo`: `ativo` continua significando "é o certificado
     * corrente da unidade" (rotação em `CertificateService::store`).
     *
     * A distinção importa: desativar um certificado vencido o faria sumir
     * do dashboard, dos alertas por e-mail e da tela da unidade — o oposto
     * do objetivo, que é deixar o vencimento visível e explícito.
     */
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dateTime('expirado_em')->nullable()->after('substituido_em');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('expirado_em');
        });
    }
};