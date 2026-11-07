<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Cursor NSU do ADN por unidade: até onde a fila de NFS-e já foi consumida.
 *
 * `historico_concluido_em` marca a primeira vez que a fila esvaziou: até lá a
 * unidade ainda está baixando o histórico (o ADN entrega tudo desde o
 * primeiro documento do CNPJ).
 */
class NfseNsu extends Model
{
    use BelongsToTenant;

    protected $table = 'nfse_nsus';

    protected $guarded = ['id'];

    protected $casts = [
        'ultimo_nsu' => 'integer',
        'dh_resp' => 'datetime',
        'historico_concluido_em' => 'datetime',
    ];

    public function unidade()
    {
        return $this->belongsTo(Unidade::class);
    }
}
