<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parâmetros de operação na SEFAZ por unidade.
     *
     * - `uf`     : sigla da unidade federativa (define o webservice/endpoint).
     * - `tp_amb` : ambiente — 1 = produção, 2 = homologação.
     *
     * Ficam na unidade (não no tenant) porque cada CNPJ se relaciona com a
     * SEFAZ do seu próprio estado, podendo o órgão ter unidades em UFs
     * distintas.
     */
    public function up(): void
    {
        Schema::table('unidades', function (Blueprint $table) {
            $table->string('uf', 2)->nullable()->after('cnpj');
            $table->unsignedTinyInteger('tp_amb')->default(2)->after('uf');
        });
    }

    public function down(): void
    {
        Schema::table('unidades', function (Blueprint $table) {
            $table->dropColumn(['uf', 'tp_amb']);
        });
    }
};