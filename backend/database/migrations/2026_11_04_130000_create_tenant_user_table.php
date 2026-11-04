<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        // Cópia dos vínculos legados: quem tinha users.tenant_id passa a ter vínculo.
        DB::table('users')
            ->whereNotNull('tenant_id')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('tenant_user')->insertOrIgnore([
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
    }
};