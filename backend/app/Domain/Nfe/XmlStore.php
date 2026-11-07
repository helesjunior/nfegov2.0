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

    /**
     * Grava o XML no caminho dado, de forma IDEMPOTENTE: se o arquivo já
     * existe com o mesmo conteúdo, só devolve o caminho (reprocessar a fila
     * não falha); com conteúdo diferente, recusa (o XML é imutável).
     *
     * @throws RuntimeException se já existir um arquivo diferente.
     */
    public function gravar(string $caminho, string $xml): string
    {
        $disco = Storage::disk($this->disco());

        if ($disco->exists($caminho)) {
            if (hash('sha256', (string) $disco->get($caminho)) === hash('sha256', $xml)) {
                return $caminho;
            }

            throw new RuntimeException(
                "O XML {$caminho} já existe com outro conteúdo e é imutável (não pode ser sobrescrito)."
            );
        }

        $disco->put($caminho, $xml);

        return $caminho;
    }

    /**
     * Caminho da NFS-e:
     * tenants/{tenant}/unidades/{unidade}/nfses/{ano}/{mes}/{chave}.xml
     *
     * Na chave de 50 dígitos da NFS-e, o AAMM de emissão fica nas posições
     * 37 a 40 (depois de município 7, ambiente 1, tipo de inscrição 1,
     * inscrição 14 e número 13).
     */
    public function caminhoNfse(Tenant $tenant, Unidade $unidade, string $chave): string
    {
        return $this->pastaNfse($tenant, $unidade, $chave).'/'.$chave.'.xml';
    }

    /**
     * Caminho de um evento de NFS-e, na pasta `eventos` do mês da nota:
     * .../nfses/{ano}/{mes}/eventos/{chave}-{nsu}.xml
     */
    public function caminhoEventoNfse(Tenant $tenant, Unidade $unidade, string $chave, int $nsu): string
    {
        return $this->pastaNfse($tenant, $unidade, $chave).'/eventos/'.$chave.'-'.$nsu.'.xml';
    }

    /**
     * Evento devolvido pela SEFIN ao registrar a manifestação do tomador:
     * .../nfses/{ano}/{mes}/eventos/{chave}-manifestacao-{codigo}.xml
     */
    public function caminhoManifestacaoNfse(Tenant $tenant, Unidade $unidade, string $chave, string $codigo): string
    {
        return $this->pastaNfse($tenant, $unidade, $chave).'/eventos/'.$chave.'-manifestacao-'.$codigo.'.xml';
    }

    private function pastaNfse(Tenant $tenant, Unidade $unidade, string $chave): string
    {
        $raiz = config('nfegov.storage.xml_root', 'tenants');
        $aamm = substr($chave, 36, 4);

        return sprintf('%s/%d/unidades/%d/nfses/20%s/%s',
            $raiz,
            $tenant->id,
            $unidade->id,
            substr($aamm, 0, 2),
            substr($aamm, 2, 2),
        );
    }

    private function disco(): string
    {
        return config('nfegov.storage.disk', 'local');
    }
}