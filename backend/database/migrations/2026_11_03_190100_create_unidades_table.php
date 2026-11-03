<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unidades gestoras de um tenant. Cada unidade tem seu próprio CNPJ e
     * seu próprio certificado e-CNPJ; é por unidade que o distDFe é consultado.
     */
    public function up(): void
    {
        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('codigo_unidade')->nullable();
            $table->string('cnpj', 14);
            $table->string('ie')->nullable();
            $table->string('im')->nullable();
            $table->string('nome');
            $table->string('nome_resumido')->nullable();
            $table->foreignId('estado_id')->nullable();
            $table->foreignId('municipio_id')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'cnpj']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades');
    }
};