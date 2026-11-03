/**
 * Formatadores de apresentação.
 *
 * Centralizados para manter a interface consistente e evitar `toLocaleString`
 * espalhado pelo código.
 */

/** Formata um CNPJ (14 dígitos) como `00.000.000/0000-00`. */
export function formatarCnpj(cnpj: string | null | undefined): string {
  const digitos = (cnpj ?? '').replace(/\D/g, '')
  if (digitos.length !== 14) return cnpj ?? '—'

  return digitos.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5')
}

/** Formata uma data ISO para `dd/mm/aaaa` (ou `—` se ausente). */
export function formatarData(iso: string | null | undefined): string {
  if (!iso) return '—'
  const data = new Date(iso)
  if (Number.isNaN(data.getTime())) return '—'

  return data.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

/** Formata uma data e hora ISO para `dd/mm/aaaa hh:mm`. */
export function formatarDataHora(iso: string | null | undefined): string {
  if (!iso) return '—'
  const data = new Date(iso)
  if (Number.isNaN(data.getTime())) return '—'

  return data.toLocaleString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/** Formata um valor monetário (número ou string decimal) em reais. */
export function formatarMoeda(valor: number | string | null | undefined): string {
  const numero = typeof valor === 'string' ? Number(valor) : (valor ?? 0)
  if (Number.isNaN(numero)) return 'R$ 0,00'

  return numero.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

/** Rótulo legível para o status de uma execução de sincronização. */
export function rotularStatusSync(status: string): string {
  const mapa: Record<string, string> = {
    em_andamento: 'Em andamento',
    sucesso: 'Sucesso',
    erro: 'Erro',
    bloqueado: 'Bloqueado (freio SEFAZ)',
  }

  return mapa[status] ?? status
}

/** Rótulo do ambiente SEFAZ a partir do `tp_amb`. */
export function rotularAmbiente(tpAmb: number | null | undefined): string {
  if (tpAmb === 1) return 'Produção'
  if (tpAmb === 2) return 'Homologação'
  return '—'
}