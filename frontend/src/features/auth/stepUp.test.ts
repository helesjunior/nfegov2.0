import { renderHook, act } from '@testing-library/react'
import { describe, it, expect, beforeEach } from 'vitest'
import { useStepUp } from './stepUp'

describe('useStepUp', () => {
  beforeEach(() => {
    const { result } = renderHook(() => useStepUp())
    act(() => result.current.limparTudo())
  })

  it('registra rota e rascunho, e consome o rascunho uma única vez', () => {
    const { result } = renderHook(() => useStepUp())

    act(() =>
      result.current.desafiar('/entidades', {
        origem: 'tenant',
        modo: 'criar',
        dados: { nome: 'X' },
      }),
    )

    expect(result.current.usarRotaRetorno()).toBe('/entidades')

    const r = result.current.consumirRascunho('tenant')
    expect(r?.dados).toEqual({ nome: 'X' })
    expect(result.current.consumirRascunho('tenant')).toBeNull()
  })

  it('limparTudo descarta rota e rascunho', () => {
    const { result } = renderHook(() => useStepUp())

    act(() =>
      result.current.desafiar('/entidades', { origem: 'tenant', modo: 'criar', dados: {} }),
    )
    act(() => result.current.limparTudo())

    expect(result.current.usarRotaRetorno()).toBeNull()
    expect(result.current.consumirRascunho('tenant')).toBeNull()
  })

  it('não devolve rascunho de outra origem', () => {
    const { result } = renderHook(() => useStepUp())

    act(() =>
      result.current.desafiar('/unidades', { origem: 'unidade', modo: 'criar', dados: {} }),
    )

    expect(result.current.consumirRascunho('tenant')).toBeNull()
  })

  it('limparRota mantém o rascunho para o formulário consumir', () => {
    const { result } = renderHook(() => useStepUp())

    act(() =>
      result.current.desafiar('/entidades', {
        origem: 'tenant',
        modo: 'criar',
        dados: { nome: 'Y' },
      }),
    )
    act(() => result.current.limparRota())

    expect(result.current.usarRotaRetorno()).toBeNull()
    expect(result.current.consumirRascunho('tenant')?.dados).toEqual({ nome: 'Y' })
  })
})