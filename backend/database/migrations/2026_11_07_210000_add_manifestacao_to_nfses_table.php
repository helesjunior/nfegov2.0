<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manifestação do tomador sobre a NFS-e (Confirmação `e203202` ou
     * Rejeição `e203206`). Preenchida quando o sistema registra o evento na
     * SEFIN ou quando o evento do tomador chega pela fila do ADN.
     */
    public function up(): void
    {
        Schema::table('nfses', function (Blueprint $table) {
            $table->string('manifestacao', 16)->nullable()->after('situacao');
            $table->dateTime('manifestada_em')->nullable()->after('manifestacao');
        });
    }

    public function down(): void
    {
        Schema::table('nfses', function (Blueprint $table) {
            $table->dropColumn(['manifestacao', 'manifestada_em']);
        });
    }
};
