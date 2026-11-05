import { useSyncExternalStore } from 'react'

/**
 * Estado efêmero do desafio de step-up (MFA recente).
 *
 * Quando uma ação sensível falha por falta de verificação recente, ela é
 * registrada aqui (rota de retorno + rascunho do formulário) e o usuário é
 * levado para `/mfa`. Após renovar, a `MfaPage` volta pela rota guardada e o
 * formulário de origem consome o rascunho para reaparecer preenchido.
 *
 * É estado de uma única navegação — não justifica uma lib de estado; um
 * singleton com `useSyncExternalStore` basta.
 */

export type OrigemRascunho = 'tenant' | 'unidade'

export interface Rascunho {
  origem: OrigemRascunho
  modo: 'criar' | 'editar'
  id?: number
  dados: Record<string, unknown>
}

interface EstadoStepUp {
  rota: string | null
  rascunho: Rascunho | null
  renovado: boolean
}

let estado: EstadoStepUp = { rota: null, rascunho: null, renovado: false }

const ouvintes = new Set<() => void>()

function notificar(): void {
  for (const ouvinte of ouvintes) ouvinte()
}

function assinar(ouvinte: () => void): () => void {
  ouvintes.add(ouvinte)
  return () => ouvintes.delete(ouvinte)
}

function instantaneo(): EstadoStepUp {
  return estado
}

export interface StepUpApi {
  /** Registra o desafio: para onde voltar e (opcional) o rascunho do formulário. */
  desafiar: (rota: string, rascunho?: Rascunho) => void
  /** Devolve e REMOVE o rascunho, se for da origem informada. */
  consumirRascunho: (origem: OrigemRascunho) => Rascunho | null
  /** Rota de retorno (ou null). */
  usarRotaRetorno: () => string | null
  /** Limpa apenas a rota e o aviso de renovação (mantém o rascunho). */
  limparRota: () => void
  /** Limpa tudo: rota, rascunho e aviso. */
  limparTudo: () => void
  /** Marca/desmarca que a verificação acabou de ser renovada (para o aviso). */
  marcarRecémVerificado: (vivo: boolean) => void
  /** A verificação foi renovada nesta navegação? */
  foiRenovado: boolean
}

export function useStepUp(): StepUpApi {
  const atual = useSyncExternalStore(assinar, instantaneo, instantaneo)

  return {
    desafiar(rota, rascunho) {
      estado = { rota, rascunho: rascunho ?? null, renovado: false }
      notificar()
    },
    consumirRascunho(origem) {
      if (!estado.rascunho || estado.rascunho.origem !== origem) return null
      const devolvido = estado.rascunho
      estado = { ...estado, rascunho: null }
      notificar()
      return devolvido
    },
    usarRotaRetorno() {
      return atual.rota
    },
    limparRota() {
      estado = { ...estado, rota: null, renovado: false }
      notificar()
    },
    limparTudo() {
      estado = { rota: null, rascunho: null, renovado: false }
      notificar()
    },
    marcarRecémVerificado(vivo) {
      estado = { ...estado, renovado: vivo }
      notificar()
    },
    foiRenovado: atual.renovado,
  }
}