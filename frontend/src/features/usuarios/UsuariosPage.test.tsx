import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const listarMock = vi.fn()
const opcoesMock = vi.fn()
const atualizarMock = vi.fn()
vi.mock('../../lib/api', () => ({
  authApi: { opcoes: (...a: unknown[]) => opcoesMock(...a) },
  usuariosApi: { listar: (...a: unknown[]) => listarMock(...a), atualizar: (...a: unknown[]) => atualizarMock(...a), criar: vi.fn(), definirAtivo: vi.fn() },
  tenantsApi: { listar: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }) },
  mensagemDeErro: (e: unknown) => (e instanceof Error ? e.message : 'erro'),
}))

let papeisAtuais = ['super-admin']
vi.mock('../auth/session', () => ({
  useSessao: () => ({
    usuario: {
      id: 1, name: 'A', email: 'a@a', tenant_id: null, tenant_ativo: null,
      tenants_disponiveis: [{ id: 1, nome: 'Entidade A' }], pode_todas: papeisAtuais.includes('super-admin'),
      papeis: papeisAtuais, permissoes: ['usuario.gerenciar'], mfa_pendente: false, mfa_confirmado: true,
    },
    recarregar: vi.fn(),
  }),
}))

import { UsuariosPage } from './UsuariosPage'

function renderizar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <UsuariosPage />
    </QueryClientProvider>,
  )
}

describe('UsuariosPage', () => {
  beforeEach(() => {
    papeisAtuais = ['super-admin']
    atualizarMock.mockReset().mockResolvedValue({})
    opcoesMock.mockReset().mockResolvedValue({ google: true, google_exclusivo: false })
    listarMock.mockReset().mockResolvedValue({
      data: [
        { id: 2, name: 'Operador Um', email: 'op@x', ativo: true, tenant_id: 1, papeis: ['operador'], tenants: [{ id: 1, nome: 'Entidade A' }], created_at: null },
        { id: 3, name: 'Pessoa Nova', email: 'nova@x', ativo: false, aprovacao_pendente: true, tenant_id: null, papeis: ['consulta'], tenants: [], created_at: null },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 2 },
      links: {},
    })
  })

  it('lista usuários', async () => {
    renderizar()
    expect(await screen.findByText('Operador Um')).toBeInTheDocument()
  })

  it('destaca o cadastro que aguarda aprovação (não como "inativo")', async () => {
    renderizar()
    await screen.findByText('Pessoa Nova')
    expect(screen.getByText(/aguardando aprovação/i)).toBeInTheDocument()
  })

  it('o pendente tem "Aprovar" (e não "Ativar"); os demais seguem com Editar e Desativar', async () => {
    renderizar()
    const pendente = (await screen.findByText('Pessoa Nova')).closest('tr') as HTMLElement
    expect(within(pendente).getByRole('button', { name: /aprovar/i })).toBeInTheDocument()
    expect(within(pendente).queryByRole('button', { name: /^ativar$/i })).not.toBeInTheDocument()

    const comum = screen.getByText('Operador Um').closest('tr') as HTMLElement
    expect(within(comum).getByRole('button', { name: /editar/i })).toBeInTheDocument()
    expect(within(comum).getByRole('button', { name: /desativar/i })).toBeInTheDocument()
  })

  it('aprovar sem escolher entidade mostra o aviso e não envia; com entidade envia', async () => {
    const user = userEvent.setup()
    papeisAtuais = ['admin-tenant']
    renderizar()

    const pendente = (await screen.findByText('Pessoa Nova')).closest('tr') as HTMLElement
    await user.click(within(pendente).getByRole('button', { name: /aprovar/i }))

    await user.click(await screen.findByRole('button', { name: /aprovar e ativar/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/escolha ao menos uma entidade/i)
    expect(atualizarMock).not.toHaveBeenCalled()

    await user.click(screen.getByRole('checkbox', { name: /entidade a/i }))
    await user.click(screen.getByRole('button', { name: /aprovar e ativar/i }))
    expect(atualizarMock).toHaveBeenCalledWith(3, expect.objectContaining({ tenants: [1] }))
  })

  it('sem o modo exclusivo, qualquer um vê o botão "Novo usuário"', async () => {
    papeisAtuais = ['admin-tenant']
    renderizar()
    await screen.findByText('Operador Um')
    expect(screen.getByRole('button', { name: /novo usuário/i })).toBeInTheDocument()
  })

  describe('modo exclusivo do Google', () => {
    beforeEach(() => {
      opcoesMock.mockResolvedValue({ google: true, google_exclusivo: true })
    })

    it('esconde "Novo usuário" para o administrador da entidade e explica o fluxo', async () => {
      papeisAtuais = ['admin-tenant']
      renderizar()

      expect(await screen.findByRole('note')).toHaveTextContent(/entrando com o google/i)
      expect(screen.queryByRole('button', { name: /novo usuário/i })).not.toBeInTheDocument()
    })

    it('o super-admin continua vendo "Novo usuário"', async () => {
      renderizar()
      await screen.findByText('Operador Um')
      expect(screen.getByRole('button', { name: /novo usuário/i })).toBeInTheDocument()
      expect(screen.queryByRole('note')).not.toBeInTheDocument()
    })

    it('ao editar, o e-mail fica travado para o administrador da entidade', async () => {
      const user = userEvent.setup()
      papeisAtuais = ['admin-tenant']
      renderizar()

      const linha = (await screen.findByText('Pessoa Nova')).closest('tr') as HTMLElement
      await user.click(within(linha).getByRole('button', { name: /aprovar/i }))

      expect(await screen.findByLabelText(/^e-mail$/i)).toHaveAttribute('readonly')
    })
  })
})