<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Auditoria de uma execução de sincronização por unidade.
 */
class SyncRun extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'sync_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'iniciado_em' => 'datetime',
        'finalizado_em' => 'datetime',
    ];

    public const STATUS_EM_ANDAMENTO = 'em_andamento';
    public const STATUS_SUCESSO = 'sucesso';
    public const STATUS_ERRO = 'erro';
    public const STATUS_BLOQUEADO = 'bloqueado';

    public function unidade()
    {
        return $this->belongsTo(Unidade::class);
    }
}