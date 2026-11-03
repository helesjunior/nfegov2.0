<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de uma manifestação enviada à SEFAZ (ex.: ciência da operação 210210).
 */
class Manifestacao extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'manifestacoes';

    protected $guarded = ['id'];

    protected $casts = [
        'enviado_em' => 'datetime',
    ];

    public function nfe()
    {
        return $this->belongsTo(Nfe::class);
    }

    public function unidade()
    {
        return $this->belongsTo(Unidade::class);
    }
}