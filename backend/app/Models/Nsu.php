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
        'fila_vazia_em' => 'datetime',
    ];

    /**
     * Segundos que faltam para a fila da unidade poder ser consultada de novo.
     *
     * Regra da SEFAZ: depois de uma resposta com ultNSU = maxNSU (fila vazia),
     * esperar 1 hora antes de consultar. Usamos `espera_fila_vazia_minutos`
     * (padrão 61) para ter folga. `0` quando já pode consultar.
     */
    public static function segundosParaLiberarFila(int $unidadeId): int
    {
        $vaziaEm = static::withoutGlobalScopes()
            ->where('unidade_id', $unidadeId)
            ->value('fila_vazia_em');

        if ($vaziaEm === null) {
            return 0;
        }

        $minutos = (int) config('nfegov.sefaz.espera_fila_vazia_minutos', 61);
        $liberaEm = \Illuminate\Support\Carbon::parse($vaziaEm)->addMinutes($minutos);

        return max(0, (int) ceil(now()->diffInSeconds($liberaEm, false)));
    }

    /**
     * Registra que a fila da unidade foi vista vazia agora.
     */
    public static function marcarFilaVazia(int $unidadeId): void
    {
        static::withoutGlobalScopes()
            ->where('unidade_id', $unidadeId)
            ->update(['fila_vazia_em' => now()]);
    }

    public function unidade()
    {
        return $this->belongsTo(Unidade::class);
    }

    public function nfes()
    {
        return $this->hasMany(Nfe::class);
    }
}