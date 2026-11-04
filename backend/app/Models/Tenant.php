<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Entidade (órgão/prefeitura) — a raiz do isolamento multitenant.
 *
 * Atenção: Tenant NÃO usa BelongsToTenant (é a própria raiz), então não
 * recebe filtro de tenant.
 */
class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tenants';

    protected $guarded = ['id'];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function unidades()
    {
        return $this->hasMany(Unidade::class);
    }

    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user');
    }
}