<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cursor NSU por unidade: até onde o distDFe já foi consumido.
 */
class Nsu extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'nsus';

    protected $guarded = ['id'];

    protected $casts = [
        'ultimo_nsu' => 'integer',
        'max_nsu' => 'integer',
        'dh_resp' => 'datetime',
    ];

    public function unidade()
    {
        return $this->belongsTo(Unidade::class);
    }

    public function nfes()
    {
        return $this->hasMany(Nfe::class);
    }
}