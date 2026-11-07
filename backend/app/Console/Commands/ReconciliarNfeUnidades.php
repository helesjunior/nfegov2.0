<?php

namespace App\Console\Commands;

use App\Domain\Nfe\NfeImporter;
use App\Models\Nfe;
use App\Models\Unidade;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Repara NF-e que o bug de "chave única por entidade" tirou de uma unidade.
 *
 * Antes da correção, quando a mesma NF-e chegava para duas unidades da mesma
 * entidade, a segunda importação reaproveitava o registro da primeira e o
 * passava para si. O XML da primeira unidade continuou no disco (imutável, na
 * pasta dela), mas sem registro no banco apontando para ele.
 *
 * Este comando:
 *  1. Percorre os XMLs gravados em `{raiz}/{tenant}/unidades/{unidade}/nfes/...`
 *     e, para cada XML sem registro (unidade, chave), reimporta a partir do
 *     próprio arquivo. O ARQUIVO NÃO É ALTERADO NEM MOVIDO: o importador vê que
 *     ele já existe e só grava o caminho no banco.
 *  2. Limpa o `xml_path` de registros que apontam para o XML de OUTRA unidade
 *     (resíduo de um resumo que "roubou" a nota). O arquivo continua no disco e
 *     passa a pertencer, no banco, à unidade dona da pasta (passo 1).
 *
 * Idempotente: rodar de novo não muda nada. Use `--dry-run` para só listar.
 */
class ReconciliarNfeUnidades extends Command
{
    protected $signature = 'nfe:reconciliar-unidades
        {--tenant= : Restringe a um tenant (id)}
        {--dry-run : Apenas mostra o que seria feito}';

    protected $description = 'Recria os registros de NF-e por unidade a partir dos XMLs já gravados (sem alterar arquivos)';

    public function handle(NfeImporter $importer): int
    {
        TenantContext::clear();

        $disco = Storage::disk(config('nfegov.storage.disk', 'local'));
        $raiz = config('nfegov.storage.xml_root', 'tenants');
        $simular = (bool) $this->option('dry-run');

        $recriadas = 0;
        $caminhosLimpos = 0;
        $falhas = 0;

        $unidades = Unidade::withoutGlobalScopes()
            ->withTrashed()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
            ->orderBy('id')
            ->get();

        foreach ($unidades as $unidade) {
            $pasta = sprintf('%s/%d/unidades/%d/nfes', $raiz, $unidade->tenant_id, $unidade->id);

            // Passo 1: XMLs da unidade sem registro da unidade.
            foreach ($disco->allFiles($pasta) as $arquivo) {
                $chave = basename($arquivo, '.xml');

                if (! preg_match('/^\d{44}$/', $chave)) {
                    continue;
                }

                $existe = Nfe::withoutGlobalScopes()
                    ->where('unidade_id', $unidade->id)
                    ->where('chave', $chave)
                    ->whereNotNull('xml_path')
                    ->exists();

                if ($existe) {
                    continue;
                }

                $this->line("Unidade {$unidade->id}: recriando NF-e {$chave}");

                if ($simular) {
                    $recriadas++;

                    continue;
                }

                try {
                    TenantContext::runAs($unidade->tenant_id, fn () => $importer->import($unidade, null, $disco->get($arquivo)));
                    $recriadas++;
                } catch (\Throwable $e) {
                    $falhas++;
                    $this->error("Unidade {$unidade->id}, chave {$chave}: {$e->getMessage()}");
                }
            }

            // Passo 2: registros da unidade apontando para a pasta de outra.
            $alheios = Nfe::withoutGlobalScopes()
                ->where('unidade_id', $unidade->id)
                ->whereNotNull('xml_path')
                ->where('xml_path', 'not like', $pasta.'/%')
                ->get();

            foreach ($alheios as $nfe) {
                $this->line("Unidade {$unidade->id}: NF-e {$nfe->chave} apontava para {$nfe->xml_path}; caminho removido do registro");
                $caminhosLimpos++;

                if (! $simular) {
                    $nfe->forceFill(['xml_path' => null, 'checksum' => null])->save();
                }
            }
        }

        $prefixo = $simular ? '[simulação] ' : '';
        $this->info("{$prefixo}NF-e recriadas: {$recriadas}. Registros com caminho de outra unidade corrigidos: {$caminhosLimpos}. Falhas: {$falhas}.");

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }
}
