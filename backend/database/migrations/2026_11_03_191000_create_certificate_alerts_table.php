<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro dos alertas de vencimento de certificado já emitidos.
     *
     * Garante idempotência: um mesmo marco (ex.: 30 dias) só gera um alerta
     * por certificado.
     */
    public function up(): void
    {
        Schema::create('certificate_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('certificate_id')->constrained('certificates')->cascadeOnDelete();
            $table->unsignedSmallInteger('dias_restantes');
            $table->dateTime('enviado_em')->nullable();
            $table->timestamps();

            $table->unique(['certificate_id', 'dias_restantes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_alerts');
    }
};