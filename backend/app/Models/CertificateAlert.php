<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Alerta de vencimento de certificado já emitido (idempotência por marco).
 */
class CertificateAlert extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'certificate_alerts';

    protected $guarded = ['id'];

    protected $casts = [
        'enviado_em' => 'datetime',
        'dias_restantes' => 'integer',
    ];

    public function certificate()
    {
        return $this->belongsTo(Certificate::class);
    }
}