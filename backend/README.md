# NFeGov 2.0 — backend

API REST em **Laravel 13 + PHP 8.4** do NFeGov 2.0: recepção e consulta de **NF-e** (SEFAZ) e
**NFS-e** (Ambiente de Dados Nacional) para entidades públicas.

O guia de instalação, a operação, as variáveis de ambiente e a referência da API estão no
[README da raiz do repositório](../README.md). Os documentos de design e de implementação estão
indexados em [`../docs/README.md`](../docs/README.md).

## Onde fica cada coisa

| Pasta | Conteúdo |
|---|---|
| `app/Domain/Sefaz` | Cliente da SEFAZ, sync por NSU (distDFe), freio anti-bloqueio, ciência da operação, recuperação manual de NF-e |
| `app/Domain/Nfe` | Importação, consulta e armazenamento imutável dos XMLs de NF-e (`XmlStore`) |
| `app/Domain/Nfse` | NFS-e Nacional: clientes mTLS do ADN e da SEFIN, sync por NSU, importação de notas e eventos, freio do ADN (`AdnGuard`), manifestação do tomador, DANFSe |
| `app/Domain/Certificate` | Certificado e-CNPJ: cifra em repouso, leitura em memória, alertas de vencimento |
| `app/Jobs` | `SyncNfeJob`, `SyncNfseJob`, recuperações manuais, exportações (CSV/XLSX/ZIP) |
| `app/Http/Controllers/Api` | Controllers da API (`/api`) |
| `app/Policies` | Autorização por tenant e permissão (`nfe.view`, `nfe.export`, `sync.executar`...) |
| `resources/danfse` | Logomarca oficial da NFS-e usada no DANFSe |
| `routes/api.php`, `routes/console.php` | Rotas da API e agenda (syncs de NF-e e NFS-e a cada 3 h, em dias úteis) |
| `config/nfegov.php` | Configuração do sistema (sync, freios, ADN, certificado, armazenamento) |
| `tests/` | Unit + Feature, incluindo o fluxo fim a fim de NF-e e o da NFS-e |

## Comandos úteis

```bash
# Sincronização manual (só enfileira; quem fala com a SEFAZ/ADN é o worker)
docker compose exec app php artisan nfegov:sync-nfe   [--unidade=ID]
docker compose exec app php artisan nfegov:sync-nfse  [--unidade=ID]

# Testes (uma execução por vez: todas usam o banco nfegov_test)
docker compose exec app php artisan test
```

> Nenhum teste chama serviço fiscal de verdade: `SefazClient`, `AdnClient` e `SefinClient` são
> substituídos por fakes. Nunca envie uma manifestação de NFS-e para testar: é um ato fiscal e
> não se desfaz.
