<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A NF-e pertence à UNIDADE (CNPJ que a recebeu no distDFe), não à entidade.
     *
     * Antes, a chave era única por tenant: quando a mesma nota chegava para duas
     * unidades da mesma entidade (ex.: transferência entre filiais), a segunda
     * importação "roubava" o registro da primeira. Agora cada unidade tem o seu
     * registro (e o seu XML, em sua própria pasta).
     */
    public function up(): void
    {
        Schema::table('nfes', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'chave']);
            $table->unique(['unidade_id', 'chave']);
            $table->index(['tenant_id', 'chave']);
        });
    }

    /**
     * Só reverte se não houver chave repetida no tenant (senão o índice falha).
     */
    public function down(): void
    {
        Schema::table('nfes', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'chave']);
            $table->dropUnique(['unidade_id', 'chave']);
            $table->unique(['tenant_id', 'chave']);
        });
    }
};
