<?php

namespace App\Domain\Nfe;

use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Armazenamento IMUTÁVEL dos XMLs fiscais.
 *
 * Os XMLs são documentos de guarda legal: nunca são sobrescritos nem
 * descartados. O caminho é determinístico e organizado por tenant/unidade e
 * por ano/mês de emissão (o ano/mês vêm da chave de acesso).
 *
 * O XML não vai para o banco; apenas o caminho e o checksum.
 */
class XmlStore
{
    /**
     * Grava o XML e devolve o caminho. RECUSA sobrescrever um arquivo existente.
     *
     * @throws RuntimeException se o arquivo já existir.
     */
    public function put(Tenant $tenant, Unidade $unidade, string $chave, string $xml): string
    {
        $caminho = $this->caminho($tenant, $unidade, $chave);
        $disco = Storage::disk($this->disco());

        if ($disco->exists($caminho)) {
            throw new RuntimeException(
                "O XML da chave {$chave} já existe e é imutável (não pode ser sobrescrito)."
            );
        }

        // Garante que o diretório exista (a API de Storage cria sob demanda).
        $disco->put($caminho, $xml);

        return $caminho;
    }

    /**
     * Lê o XML do disco.
     */
    public function get(string $caminho): string
    {
        $disco = Storage::disk($this->disco());

        if (! $disco->exists($caminho)) {
            throw new RuntimeException("XML não encontrado em {$caminho}.");
        }

        return $disco->get($caminho);
    }

    public function existe(string $caminho): bool
    {
        return Storage::disk($this->disco())->exists($caminho);
    }

    /**
     * Caminho determinístico:
     * tenants/{tenant}/unidades/{unidade}/nfes/{ano}/{mes}/{chave}.xml
     */
    public function caminho(Tenant $tenant, Unidade $unidade, string $chave): string
    {
        $raiz = config('nfegov.storage.xml_root', 'tenants');

        // posição 2..5 da chave = AAMM (ano e mês de emissão).
        $aa = substr($chave, 2, 2);
        $mm = substr($chave, 4, 2);

        return sprintf('%s/%d/unidades/%d/nfes/20%s/%s/%s.xml',
            $raiz,
            $tenant->id,
            $unidade->id,
            $aa,
            $mm,
            $chave,
        );
    }

    private function disco(): string
    {
        return config('nfegov.storage.disk', 'local');
    }
}