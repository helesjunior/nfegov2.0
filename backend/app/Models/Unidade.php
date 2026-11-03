<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unidade gestora: um CNPJ próprio, com seu próprio certificado e-CNPJ.
 * É a granularidade em que o distDFe é consultado na SEFAZ.
 */
class Unidade extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes;

    protected $table = 'unidades';

    protected $guarded = ['id'];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * Certificado e-CNPJ vigente da unidade.
     */
    public function certificate()
    {
        return $this->hasOne(Certificate::class)->where('ativo', true)->latestOfMany();
    }

    public function nsu()
    {
        return $this->hasOne(Nsu::class);
    }

    public function nfes()
    {
        return $this->hasMany(Nfe::class);
    }

    public function syncRuns()
    {
        return $this->hasMany(SyncRun::class);
    }
}