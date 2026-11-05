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

describe('perfilApi', () => {
  it('atualizar chama PUT /me e devolve o usuário', async () => {
    vi.resetModules()
    const put = vi.fn().mockResolvedValue({ data: { user: { id: 1, name: 'N', email: 'e@e' } } })
    vi.doMock('axios', () => ({
      default: { create: () => ({ put, get: vi.fn(), post: vi.fn(), delete: vi.fn() }) },
      AxiosError: class {},
    }))
    const { perfilApi } = await import('./api')

    const u = await perfilApi.atualizar({ name: 'N', email: 'e@e' })
    expect(put).toHaveBeenCalledWith('/me', { name: 'N', email: 'e@e' })
    expect(u.name).toBe('N')

    vi.doUnmock('axios')
    vi.resetModules()
  })

  it('trocarSenha chama PUT /me/password', async () => {
    vi.resetModules()
    const put = vi.fn().mockResolvedValue({ data: { user: { id: 1 } } })
    vi.doMock('axios', () => ({
      default: { create: () => ({ put, get: vi.fn(), post: vi.fn(), delete: vi.fn() }) },
      AxiosError: class {},
    }))
    const { perfilApi } = await import('./api')

    await perfilApi.trocarSenha({ current_password: 'a', password: 'b', password_confirmation: 'b' })
    expect(put).toHaveBeenCalledWith('/me/password', {
      current_password: 'a',
      password: 'b',
      password_confirmation: 'b',
    })

    vi.doUnmock('axios')
    vi.resetModules()
  })

  it('desabilitarMfa chama DELETE /me/mfa com o código', async () => {
    vi.resetModules()
    const del = vi.fn().mockResolvedValue({ data: { user: { id: 1, mfa_confirmado: false } } })
    vi.doMock('axios', () => ({
      default: { create: () => ({ delete: del, get: vi.fn(), post: vi.fn(), put: vi.fn() }) },
      AxiosError: class {},
    }))
    const { perfilApi } = await import('./api')

    const u = await perfilApi.desabilitarMfa('123456')
    expect(del).toHaveBeenCalledWith('/me/mfa', { data: { code: '123456' } })
    expect(u.mfa_confirmado).toBe(false)

    vi.doUnmock('axios')
    vi.resetModules()
  })
})