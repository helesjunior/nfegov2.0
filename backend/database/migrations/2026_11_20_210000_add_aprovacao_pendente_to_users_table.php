<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cadastro criado pelo login com Google (modo "pendente") e ainda sem
            // entidade: aparece na lista de Usuários para quem pode aprová-lo.
            // Deixa de ser pendente quando um administrador o vincula a uma entidade.
            $table->boolean('aprovacao_pendente')->default(false)->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('aprovacao_pendente');
        });
    }
};
