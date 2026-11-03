<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Certificado e-CNPJ de uma unidade.
 *
 * SEGURANÇA:
 * - `senha_encrypted` usa o cast `encrypted` (AES-256 do Laravel, APP_KEY
 *   fora do banco). O valor nunca é devolvido por nenhuma API.
 * - O PFX fica cifrado em disco; só o caminho (`arquivo_path`) é indexado.
 * - `certificado` e `senha` são marcados como `$hidden`.
 */
class Certificate extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'certificates';

    protected $guarded = ['id'];

    protected $hidden = ['senha_encrypted', 'arquivo_path'];

    protected $casts = [
        'senha_encrypted' => 'encrypted',
        'ativo' => 'boolean',
        'valido_de' => 'datetime',
        'valido_ate' => 'datetime',
        'substituido_em' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function unidade()
    {
        return $this->belongsTo(Unidade::class);
    }

    public function alerts()
    {
        return $this->hasMany(CertificateAlert::class);
    }

    /**
     * Dias restantes até o vencimento (negativo quando já venceu).
     */
    public function diasRestantes(): int
    {
        return (int) now('America/Sao_Paulo')->startOfDay()->diffInDays(
            $this->valido_ate->copy()->startOfDay(),
            false
        );
    }

    public function vencido(): bool
    {
        return $this->valido_ate->isPast();
    }
}