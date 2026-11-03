import { describe, it, expect } from 'vitest'
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