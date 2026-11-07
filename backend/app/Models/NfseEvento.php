<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Evento de NFS-e recebido pelo ADN (cancelamento, manifestação etc.).
 */
class NfseEvento extends Model
{
    use BelongsToTenant;

    protected $table = 'nfse_eventos';

    protected $guarded = ['id'];

    protected $casts = [
        'nsu' => 'integer',
        'data_evento' => 'datetime',
    ];

    public function nfse()
    {
        return $this->belongsTo(Nfse::class);
    }

    public function unidade()
    {
        return $this->belongsTo(Unidade::class);
    }
}
