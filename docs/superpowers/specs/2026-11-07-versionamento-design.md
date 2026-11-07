# Design — Versionamento do NFeGov 2.0

Data: 2026-11-07

## Contexto e motivação

O sistema não tinha versionamento: `frontend/package.json` estava em `0.0.0`,
não havia arquivo de versão no backend e nenhuma versão era exibida aos
usuários. Sem isso, é impossível saber qual build está em execução numa
instalação — o que dificulta suporte, testes em produção e o próprio controle
de mudanças.

## Objetivo

Estabelecer a versão inicial **2.0.0** como fonte de verdade única, exibi-la no
rodapé do menu (frontal esquerda) no formato:

```
NFeGov2.0 - v. 2.0.0-20261106.175300
```

onde `20261106.175300` é a **data e hora em que a build foi executada**
(gravada no build, não em runtime).

## Decisões de design

1. **Fonte de verdade única:** arquivo `backend/VERSION` com o número semântico.
   - Fica dentro de `backend/` porque é o único diretório montado no container
     (`docker-compose.yml`: `./backend:/var/www/html`). A raiz do repo **não** é
     visível de dentro do container.
   - Lido pelo backend via `config('nfegov.versao.numero')` e exposto por
     `GET /api/version`.
   - `frontend/package.json.version` é mantido em sincronia (conveniência;
     não é a fonte de verdade).

2. **Timestamp da build no momento do build:** o script
   `frontend/scripts/build-info.mjs` grava `backend/build.json` com
   `{ "build_at": "YYYYMMDD.HHMMSS" }` antes do `vite build`
   (`npm run build`). Não é calculado em runtime.

3. **Exposição pública:** `GET /api/version` é público (fora do grupo
   autenticado). A versão não é dado sensível e o rodapé já aparece na tela de
   login. Payload:
   ```json
   { "data": { "versao": "2.0.0", "build_at": "20261106.175300",
               "rotulo": "2.0.0-20261106.175300" } }
   ```

4. **Exibição:** `Sidebar` recebe `versao` (o rótulo) via prop; o `AppShell`
   busca em `/api/version` com React Query (`staleTime: Infinity`, falha
   silenciosa). O rodapé fica no fim da coluna do menu (`.shell__versao`).

5. **Processo:** daqui em diante, toda mudança entra por **pull request**; a
   `main` é protegida no GitHub (ação do mantenedor). SemVer documentado no
   README.

## Escopo

**Dentro:**
- `backend/VERSION`, `backend/build.json` (artefato, não versionado).
- `config/nfegov.php` (`versao.numero`, `versao.build_at`).
- `VersaoController` + rota pública `GET /api/version`.
- `build-info.mjs` + hook no `npm run build`.
- `versaoApi` no frontend, prop na `Sidebar`, CSS do rodapé.
- Docs: seção de versionamento/PR no README + este design.

**Fora:**
- Tags/branches do Git como fonte de versão (optamos por arquivo).
- Geração automática de changelog.
- Configuração de proteção de branch (é feita no GitHub, pelo mantenedor).

## Testes

- Backend (`VersaoApiTest`): endpoint público, versão `2.0.0`, rótulo com e sem
  build, ausência de autenticação.
- Frontend (`Sidebar.test.tsx`): rodapé com o formato pedido; oculto sem versão;
  filtragem de itens preservada.