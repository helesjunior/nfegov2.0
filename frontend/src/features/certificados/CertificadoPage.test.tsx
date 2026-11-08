import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { CertificadoPage } from './CertificadoPage'

/**
 * Tela de certificado de uma unidade.
 *
 * Cobre o comportamento essencial:
 * - mostra metadados (titular, validade e dias restantes);
 * - sinaliza certificado vencido;
 * - oferece a rotação e, ao receber 403 de step-up, exige o código TOTP
 *   antes de reenviar o upload.
 *
 * A API é mockada — o teste cobre a tela, não a rede.
 */

const unidadeMock = {
  id: 7,
  nome: 'AGU - Unidade Central',
  nome_resumido: 'AGU Central',
  cnpj: '11222333000181',
  uf: 'DF',
  tp_amb: 2,
  ativo: true,
  certificado: null as unknown,
}

// Mock do cliente HTTP compartilhado (lib/api) — evitamos rede de verdade.
const obterMock = vi.fn()
const enviarMock = vi.fn()
const verifyMfaMock = vi.fn()

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    certificadoApi: {
      obter: (...args: unknown[]) => obterMock(...args),
      enviar: (...args: unknown[]) => enviarMock(...args),
    },
    mfaApi: { verify: (...args: unknown[]) => verifyMfaMock(...args) },
  }
})

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <CertificadoPage unidade={unidadeMock as never} />
    </QueryClientProvider>,
  )
}

describe('CertificadoPage', () => {
  beforeEach(() => {
    obterMock.mockReset()
    enviarMock.mockReset()
    verifyMfaMock.mockReset()
  })

  it('exibe a validade e os dias restantes do certificado', async () => {
    obterMock.mockResolvedValue({
      podeAtualizar: true,
      certificado: {
        id: 1,
        unidade_id: 7,
        cnpj: '11222333000181',
        titular: 'ADVOCACIA-GERAL DA UNIAO',
        emissor: 'AC SOLUTI',
        valido_de: '2025-01-01T00:00:00+00:00',
        valido_ate: '2027-01-01T00:00:00+00:00',
        dias_restantes: 180,
        vencido: false,
        ativo: true,
      },
    })

    renderPage()

    expect(await screen.findByText(/ADVOCACIA-GERAL DA UNIAO/i)).toBeInTheDocument()
    expect(screen.getByText(/180/)).toBeInTheDocument()
    expect(screen.queryByText(/vencido/i)).not.toBeInTheDocument()
  })

  it('sinaliza quando o certificado está vencido', async () => {
    obterMock.mockResolvedValue({
      podeAtualizar: true,
      certificado: {
        id: 1,
        unidade_id: 7,
        cnpj: '11222333000181',
        titular: 'ADVOCACIA-GERAL DA UNIAO',
        emissor: 'AC SOLUTI',
        valido_de: '2020-01-01T00:00:00+00:00',
        valido_ate: '2021-01-01T00:00:00+00:00',
        dias_restantes: -100,
        vencido: true,
        ativo: true,
      },
    })

    renderPage()

    expect((await screen.findAllByText(/vencido/i)).length).toBeGreaterThan(0)
  })

  it('avisa quando não há certificado cadastrado', async () => {
    obterMock.mockResolvedValue({ certificado: null, podeAtualizar: true })

    renderPage()

    expect(await screen.findByText(/nenhum certificado/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /enviar certificado/i })).toBeInTheDocument()
  })

  it('exige o código TOTP (step-up) antes de reenviar o upload após 403', async () => {
    const user = userEvent.setup()

    obterMock.mockResolvedValue({
      podeAtualizar: true,
      certificado: {
        id: 1,
        unidade_id: 7,
        cnpj: '11222333000181',
        titular: 'ADVOCACIA-GERAL DA UNIAO',
        emissor: 'AC SOLUTI',
        valido_de: '2025-01-01T00:00:00+00:00',
        valido_ate: '2027-01-01T00:00:00+00:00',
        dias_restantes: 180,
        vencido: false,
        ativo: true,
      },
    })

    // 1ª tentativa: backend pede step-up (AxiosError com a flag específica).
    const erroStepUp = {
      isAxiosError: true,
      response: { status: 403, data: { mfa_step_up_required: true } },
    }
    enviarMock.mockRejectedValueOnce(erroStepUp)
    verifyMfaMock.mockResolvedValue({ recente_ate: '2026-01-01T00:00:00+00:00' })
    // 2ª tentativa (após verificar): sucesso.
    enviarMock.mockResolvedValueOnce({ id: 2 })

    renderPage()

    // Abre o diálogo de rotação.
    await user.click(await screen.findByRole('button', { name: /rotacionar/i }))

    // Escolhe o arquivo e a senha.
    const arquivo = new File(['pfx'], 'novo.pfx', { type: 'application/x-pkcs12' })
    await user.upload(screen.getByLabelText(/arquivo/i), arquivo)
    await user.type(screen.getByLabelText(/senha/i), 'segredo')
    await user.click(screen.getByRole('button', { name: /enviar/i }))

    // O backend respondeu 403 com mfa_step_up_required → deve pedir o código.
    expect(await screen.findByLabelText(/código/i)).toBeInTheDocument()

    await user.type(screen.getByLabelText(/código/i), '123456')
    await user.click(screen.getByRole('button', { name: /verificar e reenviar/i }))

    await waitFor(() => expect(verifyMfaMock).toHaveBeenCalledWith('123456'))
    // Após verificar, o upload é reenviado automaticamente.
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(2))
  })

  it('operador com certificado em dia não vê o botão de atualizar', async () => {
    obterMock.mockResolvedValue({
      podeAtualizar: false,
      certificado: {
        id: 1,
        unidade_id: 7,
        cnpj: '11222333000181',
        titular: 'ADVOCACIA-GERAL DA UNIAO',
        emissor: 'AC SOLUTI',
        valido_de: '2025-01-01T00:00:00+00:00',
        valido_ate: '2027-01-01T00:00:00+00:00',
        dias_restantes: 180,
        vencido: false,
        ativo: true,
      },
    })

    renderPage()

    expect(await screen.findByText(/somente depois que ele vencer/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /rotacionar|atualizar/i })).not.toBeInTheDocument()
  })

  it('certificado vencido com permissão oferece "Atualizar certificado"', async () => {
    obterMock.mockResolvedValue({
      podeAtualizar: true,
      certificado: {
        id: 1,
        unidade_id: 7,
        cnpj: '11222333000181',
        titular: 'ADVOCACIA-GERAL DA UNIAO',
        emissor: 'AC SOLUTI',
        valido_de: '2020-01-01T00:00:00+00:00',
        valido_ate: '2021-01-01T00:00:00+00:00',
        dias_restantes: -100,
        vencido: true,
        ativo: true,
      },
    })

    renderPage()

    expect(await screen.findByRole('button', { name: /atualizar certificado/i })).toBeInTheDocument()
  })
})
