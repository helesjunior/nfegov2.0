<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Emitente (fornecedor) normalizado por CNPJ dentro do tenant.
 */
class Fornecedor extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'fornecedores';

    protected $guarded = ['id'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function nfes()
    {
        return $this->hasMany(Nfe::class);
    }
}