<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * NFS-e recebida pelo Ambiente de Dados Nacional (ADN).
 *
 * Pertence à UNIDADE que a recebeu. `papel` diz como o CNPJ da unidade
 * aparece na nota. O XML mora em disco; aqui ficam metadados e o caminho.
 */
class Nfse extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'nfses';

    protected $guarded = ['id'];

    protected $casts = [
        'nsu' => 'integer',
        'valor_servico' => 'decimal:2',
        'valor_liquido' => 'decimal:2',
        'valor_iss' => 'decimal:2',
        'iss_retido' => 'boolean',
        'data_emissao' => 'datetime',
        'data_processamento' => 'datetime',
        'competencia' => 'date',
    ];

    public const PAPEL_TOMADOR = 'tomador';
    public const PAPEL_PRESTADOR = 'prestador';
    public const PAPEL_INTERMEDIARIO = 'intermediario';
    public const PAPEL_OUTRO = 'outro';

    public const SITUACAO_NORMAL = 'normal';
    public const SITUACAO_CANCELADA = 'cancelada';
    public const SITUACAO_SUBSTITUIDA = 'substituida';

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function unidade()
    {
        return $this->belongsTo(Unidade::class);
    }

    public function eventos()
    {
        return $this->hasMany(NfseEvento::class)->orderBy('nsu');
    }
}
