<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando a fila do distDFe da unidade foi vista vazia (ultNSU = maxNSU).
 *
 * A SEFAZ exige 1 hora sem consultar a fila depois disso; consultar antes
 * devolve cStat 656 (consumo indevido) e bloqueia o IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nsus', function (Blueprint $table) {
            $table->timestamp('fila_vazia_em')->nullable()->after('dh_resp');
        });
    }

    public function down(): void
    {
        Schema::table('nsus', function (Blueprint $table) {
            $table->dropColumn('fila_vazia_em');
        });
    }
};
