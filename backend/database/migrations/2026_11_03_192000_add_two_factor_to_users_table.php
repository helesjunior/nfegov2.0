<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos de MFA (TOTP) no usuário.
     *
     * SEGURANÇA:
     * - `two_factor_secret` é gravado CIFRADO (cast `encrypted` no model),
     *   usando a APP_KEY — nunca em texto plano no banco.
     * - `two_factor_confirmed_at`: só preenchido após o usuário provar que
     *   configurou o app autenticador com um código válido.
     * - `mfa_recent_at`: marca a última verificação bem-sucedida; usada pelo
     *   step-up (`RequireRecentMfa`) em operações sensíveis, como a rotação
     *   de certificado.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->dateTime('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->dateTime('mfa_recent_at')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'mfa_recent_at']);
        });
    }
};