<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trilha de auditoria de ações sensíveis (rotação de certificado,
     * manifestação, download de XML/DANFE, alterações administrativas).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acao');
            $table->string('entidade')->nullable();
            $table->string('entidade_id')->nullable();
            $table->jsonb('contexto')->nullable();
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'acao']);
            $table->index(['entidade', 'entidade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};