import type { Ordem } from '../../lib/ordem'

interface Props<C extends string> {
  campo: C
  rotulo: string
  ordem: Ordem<C>
  onOrdenar: (campo: C) => void
}

/**
 * Cabeçalho de tabela clicável que ordena a listagem pela coluna.
 * A seta mostra o sentido atual; `aria-sort` informa leitores de tela.
 */
export function ColunaOrdenavel<C extends string>({ campo, rotulo, ordem, onOrdenar }: Props<C>) {
  const ativa = ordem.campo === campo
  const crescente = ativa && ordem.direcao === 'asc'
  const titulo = ativa
    ? `Ordenado por ${rotulo.toLowerCase()} (${crescente ? 'crescente' : 'decrescente'}). Clique para inverter.`
    : `Ordenar por ${rotulo.toLowerCase()}`

  return (
    <th aria-sort={ativa ? (crescente ? 'ascending' : 'descending') : 'none'}>
      <button
        type="button"
        className={ativa ? 'tabela__ordenar tabela__ordenar--ativa' : 'tabela__ordenar'}
        onClick={() => onOrdenar(campo)}
        title={titulo}
      >
        {rotulo}
        <span className="tabela__seta" aria-hidden="true">
          {ativa ? (crescente ? '▲' : '▼') : '↕'}
        </span>
      </button>
    </th>
  )
}
