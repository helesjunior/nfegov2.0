import { describe, it, expect, vi } from 'vitest'
import { ehErroDeStepUp } from './api'

describe('ehErroDeStepUp', () => {
  it('detecta a flag mfa_step_up_required no corpo 403', () => {
    expect(
      ehErroDeStepUp({ response: { status: 403, data: { mfa_step_up_required: true } } }),
    ).toBe(true)
  })

  it('retorna false para um 403 comum (sem a flag)', () => {
    expect(ehErroDeStepUp({ response: { status: 403, data: { message: 'sem permissão' } } })).toBe(
      false,
    )
  })
})

describe('novos endpoints administrativos', () => {
  it('sessaoApi.trocarTenant chama POST /sessao/tenant e devolve tenant_ativo', async () => {
    vi.resetModules()
    const post = vi.fn().mockResolvedValue({ data: { tenant_ativo: { id: 3, nome: 'A' } } })
    vi.doMock('axios', () => ({
      default: { create: () => ({ post, get: vi.fn() }) },
      AxiosError: class {},
    }))
    const { sessaoApi } = await import('./api')

    const r = await sessaoApi.trocarTenant(3)
    expect(post).toHaveBeenCalledWith('/sessao/tenant', { tenant_id: 3 })
    expect(r.tenant_ativo?.id).toBe(3)

    vi.doUnmock('axios')
    vi.resetModules()
  })
})