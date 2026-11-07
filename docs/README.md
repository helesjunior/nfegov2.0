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
| Versionamento | [`specs/2026-11-07-versionamento-design.md`](superpowers/specs/2026-11-07-versionamento-design.md) | — |

## Capturas de tela

A pasta [`capturas/`](capturas/) guarda imagens da interface (login, lista de NF-e e painel
claro/escuro). Foram tiradas antes do painel de NFS-e (2.6.0) e do menu **NFS-e**.

## Como manter

- Toda spec nova entra em `docs/superpowers/specs/` com data no nome (`AAAA-MM-DD-assunto-design.md`)
  e ganha uma linha neste índice.
- Ao entregar uma funcionalidade, a spec registra o que **mudou** em relação ao plano e o que
  **não** foi confirmado, para ninguém tomar suposição por fato.
- O `README.md` da raiz descreve o sistema **como ele está hoje**: atualize-o junto com o código.
