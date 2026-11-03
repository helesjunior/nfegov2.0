<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * NF-e recebida via distDFe.
 *
 * `estado`: 'resumo' (só resNFe) ou 'completo' (procNFe/nfeProc).
 * O XML mora em disco; aqui ficam apenas metadados e o caminho.
 */
class Nfe extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'nfes';

    protected $guarded = ['id'];

    protected $casts = [
        'valor_total' => 'decimal:2',
        'data_emissao' => 'datetime',
        'data_saida_entrada' => 'datetime',
        'emitente_proprio' => 'boolean',
    ];

    public const ESTADO_RESUMO = 'resumo';
    public const ESTADO_COMPLETO = 'completo';

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function unidade()
    {
        return $this->belongsTo(Unidade::class);
    }

    public function nsu()
    {
        return $this->belongsTo(Nsu::class);
    }

    public function fornecedor()
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function itens()
    {
        return $this->hasMany(NfeItem::class);
    }

    public function manifestacoes()
    {
        return $this->hasMany(Manifestacao::class);
    }

    public function completo(): bool
    {
        return $this->estado === self::ESTADO_COMPLETO;
    }
}