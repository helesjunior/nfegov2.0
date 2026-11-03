# NFeGov 2.0

**NFE-Gov 2.0** — sistema multitenant de recepção e consulta de NF-e para entidades públicas.

Conecta-se periodicamente à SEFAZ (a cada 3 horas), via certificado digital e-CNPJ de cada
unidade gestora, para baixar todas as NF-e destinadas ao CNPJ da unidade, armazená-las e
disponibilizá-las para consulta.

Reescrita do [helesjunior/nfegov](https://github.com/helesjunior/nfegov) com Laravel 13 + PHP 8.4,
API REST e SPA React.

## Stack

| Camada | Tecnologia |
|---|---|
| Backend | Laravel 13 (API REST) |
| Frontend | React + Vite + TypeScript |
| Banco | PostgreSQL 16 |
| Fila / Cache | Redis + Laravel Horizon |
| Autenticação | Laravel Sanctum (SPA same-origin) + MFA (TOTP) |
| RBAC | spatie/laravel-permission |
| Integração SEFAZ | nfephp-org/sped-nfe ^5.2 |
| Infra | Docker + docker compose |

## Estrutura

```
nfegov2.0/
├── backend/          # Laravel 13 (API REST)
├── frontend/         # React + Vite (SPA)
├── docker/           # Dockerfile do PHP e config do Nginx
├── docs/             # design e plano de implementação
└── docker-compose.yml
```

## Requisitos

- Docker e Docker Compose

## Como subir

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --seed
```

A aplicação sobe em `http://localhost:8080`.

## Desenvolvimento

A SPA (React/Vite) é servida pelo próprio Laravel, a partir do build em
`backend/public/spa`. Para compilar a interface:

```bash
cd frontend
npm install
npm run build     # gera ../backend/public/spa
```

Para desenvolvimento com recarga a quente, rode `npm run dev` em `frontend/`
(porta 5173, com proxy de `/api` e `/sanctum` para `localhost:8000`).

> **Nota (Windows/macOS):** em desenvolvimento, o bind mount do código no
> container (Docker Desktop → WSL2) torna cada requisição do Laravel lenta
> (alguns segundos), pois o bootstrap lê centenas de arquivos através da
> ponte de sistema de arquivos. Isso é uma característica do ambiente, não um
> defeito da aplicação. Em produção, o código é copiado para a imagem.

## Documentação

- Design: [`docs/nfegov/2026-11-03-nfegov-design.md`](docs/nfegov/2026-11-03-nfegov-design.md)
- Plano de implementação: [`docs/superpowers/plans/2026-11-03-nfegov-2.0.md`](docs/superpowers/plans/2026-11-03-nfegov-2.0.md)

## Licença

MIT.