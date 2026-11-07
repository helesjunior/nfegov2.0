import type { PapelNfse, SituacaoNfse } from '../../lib/api'

export const ROTULO_PAPEL: Record<PapelNfse, string> = {
  tomador: 'Recebida',
  prestador: 'Emitida',
  intermediario: 'Intermediada',
  outro: 'Outro',
}

export const ROTULO_SITUACAO: Record<SituacaoNfse, string> = {
  normal: 'Normal',
  cancelada: 'Cancelada',
  substituida: 'Substituída',
}

export function classeSituacao(situacao: SituacaoNfse): string {
  if (situacao === 'normal') return 'selo selo--ok'
  if (situacao === 'cancelada') return 'selo selo--erro'
  return 'selo selo--alerta'
}

/** `CANCELAMENTO_POR_SUBSTITUICAO` → `Cancelamento por substituicao`. */
export function rotularEvento(tipo: string): string {
  const texto = tipo.replace(/_/g, ' ').toLowerCase()
  return texto.charAt(0).toUpperCase() + texto.slice(1)
}
