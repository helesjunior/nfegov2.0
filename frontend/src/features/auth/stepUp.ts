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

/**
 * Ações com identidade ESTÁVEL (fora do corpo do hook), para que possam ser
 * dependências de efeitos sem provocar re-render/limpeza em laço.
 */
const acoes = {
  desafiar(rota: string, rascunho?: Rascunho): void {
    estado = { rota, rascunho: rascunho ?? null, renovado: false }
    notificar()
  },
  consumirRascunho(origem: OrigemRascunho): Rascunho | null {
    if (!estado.rascunho || estado.rascunho.origem !== origem) return null
    const devolvido = estado.rascunho
    estado = { ...estado, rascunho: null }
    notificar()
    return devolvido
  },
  limparRota(): void {
    estado = { ...estado, rota: null }
    notificar()
  },
  limparTudo(): void {
    estado = { rota: null, rascunho: null, renovado: false }
    notificar()
  },
  marcarRecémVerificado(vivo: boolean): void {
    estado = { ...estado, renovado: vivo }
    notificar()
  },
} satisfies Omit<StepUpApi, 'usarRotaRetorno' | 'foiRenovado'>

export interface StepUpApi {
  /** Registra o desafio: para onde voltar e (opcional) o rascunho do formulário. */
  desafiar: (rota: string, rascunho?: Rascunho) => void
  /** Devolve e REMOVE o rascunho, se for da origem informada. */
  consumirRascunho: (origem: OrigemRascunho) => Rascunho | null
  /** Rota de retorno (ou null). */
  usarRotaRetorno: () => string | null
  /** Limpa apenas a rota (mantém o rascunho e o aviso de renovação). */
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
    desafiar: acoes.desafiar,
    consumirRascunho: acoes.consumirRascunho,
    usarRotaRetorno: () => atual.rota,
    limparRota: acoes.limparRota,
    limparTudo: acoes.limparTudo,
    marcarRecémVerificado: acoes.marcarRecémVerificado,
    foiRenovado: atual.renovado,
  }
}