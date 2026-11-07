# Documentação do NFeGov 2.0

Índice dos documentos de design, specs e planos. O guia de uso e de operação do sistema fica
no [`README.md`](../README.md) da raiz.

## Design original

| Documento | Assunto |
|---|---|
| [`nfegov/2026-11-03-nfegov-design.md`](nfegov/2026-11-03-nfegov-design.md) | Design da reescrita do NFeGov (escopo original: recepção de NF-e) |
| [`superpowers/plans/2026-11-03-nfegov-2.0.md`](superpowers/plans/2026-11-03-nfegov-2.0.md) | Plano de implementação da 2.0 |

## NFS-e (padrão nacional)

| Documento | Assunto |
|---|---|
| [`superpowers/specs/2026-11-07-nfse-nacional-design.md`](superpowers/specs/2026-11-07-nfse-nacional-design.md) | Recepção pelo Ambiente de Dados Nacional (2.4.0), DANFSe, manifestação do tomador, ZIP e busca por chave (2.5.0) e painel (2.6.0). Traz as fontes oficiais, o contrato das APIs, o que foi confirmado em chamadas reais e o que **não** foi confirmado |

## Specs e planos por assunto

| Assunto | Spec | Plano |
|---|---|---|
| CRUDs administrativos, multitenancy e seletor de entidade | [`specs/2026-11-04-cruds-multitenancy-design.md`](superpowers/specs/2026-11-04-cruds-multitenancy-design.md) | [`plans/2026-11-04-cruds-multitenancy.md`](superpowers/plans/2026-11-04-cruds-multitenancy.md) |
| Marcação explícita de certificados vencidos | — | [`plans/2026-11-04-marcar-certificados-vencidos.md`](superpowers/plans/2026-11-04-marcar-certificados-vencidos.md) |
| Perfil do usuário e MFA opcional | [`specs/2026-11-05-perfil-mfa-opcional-design.md`](superpowers/specs/2026-11-05-perfil-mfa-opcional-design.md) | [`plans/2026-11-05-perfil-mfa-opcional.md`](superpowers/plans/2026-11-05-perfil-mfa-opcional.md) |
| Step-up de MFA com retorno e rascunho | [`specs/2026-11-05-step-up-mfa-retorno-design.md`](superpowers/specs/2026-11-05-step-up-mfa-retorno-design.md) | [`plans/2026-11-05-step-up-mfa-retorno.md`](superpowers/plans/2026-11-05-step-up-mfa-retorno.md) |
| Recuperação manual de NF-e (chave e NSU) | [`specs/2026-11-06-recuperacao-manual-nfe-design.md`](superpowers/specs/2026-11-06-recuperacao-manual-nfe-design.md) | [`plans/2026-11-06-recuperacao-manual-nfe.md`](superpowers/plans/2026-11-06-recuperacao-manual-nfe.md) |
| Diagnóstico e correção da recuperação (cStat 137) | [`specs/2026-11-06-diagnostico-recuperacao-nfe-137.md`](superpowers/specs/2026-11-06-diagnostico-recuperacao-nfe-137.md) | [`plans/2026-11-06-correcao-recuperacao-nfe-137.md`](superpowers/plans/2026-11-06-correcao-recuperacao-nfe-137.md) |
| Retentativa automática da recuperação manual | [`specs/2026-11-07-recuperacao-retentativa-automatica-design.md`](superpowers/specs/2026-11-07-recuperacao-retentativa-automatica-design.md) | [`plans/2026-11-07-recuperacao-retentativa-automatica.md`](superpowers/plans/2026-11-07-recuperacao-retentativa-automatica.md) |
| Ritmo da sincronização de NF-e (regra de 1 h, continuação, intervalo) | [`specs/2026-11-07-ritmo-sync-nfe-design.md`](superpowers/specs/2026-11-07-ritmo-sync-nfe-design.md) | — |
| Versionamento | [`specs/2026-11-07-versionamento-design.md`](superpowers/specs/2026-11-07-versionamento-design.md) | — |

## Capturas de tela

A pasta [`capturas/`](capturas/) guarda imagens da interface: login, painel (claro e escuro),
lista de NF-e, lista e detalhe de NFS-e, diálogo de manifestação, sincronizações e unidades.
Os dados são **fictícios** (nomes de empresas, CNPJs, e-mail e nome do usuário trocados por
genéricos antes de cada print). A captura da lista de NF-e (`nfes.png`) é da 2.1.0, com dados de
demonstração, e ainda não mostra o menu **NFS-e**; as demais são da 2.6.0.

Para tirar capturas novas sem expor dados reais, troque nomes e documentos no navegador **antes**
do print e confira a imagem depois; nunca publique captura de ambiente com dados reais.

## Como manter

- Toda spec nova entra em `docs/superpowers/specs/` com data no nome (`AAAA-MM-DD-assunto-design.md`)
  e ganha uma linha neste índice.
- Ao entregar uma funcionalidade, a spec registra o que **mudou** em relação ao plano e o que
  **não** foi confirmado, para ninguém tomar suposição por fato.
- O `README.md` da raiz descreve o sistema **como ele está hoje**: atualize-o junto com o código.
