<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Item de uma NF-e (preenchido apenas quando o XML completo chega).
 */
class NfeItem extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'nfe_items';

    protected $guarded = ['id'];

    protected $casts = [
        'quantidade' => 'decimal:4',
        'valor_unitario' => 'decimal:4',
        'valor_total' => 'decimal:2',
        'quantidade_tributado' => 'decimal:4',
        'valor_unitario_tributado' => 'decimal:4',
    ];

    public function nfe()
    {
        return $this->belongsTo(Nfe::class);
    }
}