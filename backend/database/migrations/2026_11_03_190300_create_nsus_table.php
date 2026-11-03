<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cursor NSU (Número Sequencial Único) por unidade.
     *
     * O distDFe é incremental: cada unidade guarda até onde já consumiu,
     * permitindo retomada correta após pausas/bloqueios do fisco.
     */
    public function up(): void
    {
        Schema::create('nsus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->cascadeOnDelete();
            $table->unsignedBigInteger('ultimo_nsu')->default(0);
            $table->unsignedBigInteger('max_nsu')->default(0);
            $table->dateTime('dh_resp')->nullable();
            $table->timestamps();

            $table->index(['unidade_id', 'ultimo_nsu']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nsus');
    }
};