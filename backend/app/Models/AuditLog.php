<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Trilha de auditoria de ações sensíveis.
 *
 * Não usa BelongsToTenant: precisa registrar contexto global e ser consultável
 * por administradores da plataforma.
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'contexto' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}