import { useState } from 'react'
import type { DashboardResumo } from '../../lib/api'
import { formatarMoeda } from '../../lib/format'

type Dia = DashboardResumo['graficos']['por_dia'][number]
type Fornecedor = DashboardResumo['graficos']['fornecedores'][number]

/** `2026-10-09` -> `09/10` (sem passar por Date, para não sofrer com fuso). */
function diaMes(data: string): string {
  const [, mes, dia] = data.split('-')
  return `${dia}/${mes}`
}

function qtdNfe(n: number): string {
  return `${n} NF-e`
}

/**
 * Colunas com a quantidade de NF-e por dia de emissão (últimos 30 dias).
 *
 * Uma única série, na cor primária. O dia sob o cursor (ou foco do teclado)
 * aparece no cabeçalho; sem interação, mostra o total do período.
 */
export function GraficoPorDia({ dias }: { dias: Dia[] }) {
  const [ativo, setAtivo] = useState<number | null>(null)
  const maximo = Math.max(1, ...dias.map((d) => d.quantidade))
  const total = dias.reduce((s, d) => s + d.quantidade, 0)
  const valor = dias.reduce((s, d) => s + d.valor, 0)
  const sel = ativo !== null ? dias[ativo] : null

  return (
    <div className="card grafico">
      <div className="grafico__topo">
        <div>
          <h2 className="grafico__titulo">NF-e por dia</h2>
          <p className="grafico__sub">Data de emissão · últimos 30 dias</p>
        </div>
        <div className="grafico__destaque" aria-live="polite" data-testid="destaque-dia">
          {sel ? (
            <>
              <strong>{qtdNfe(sel.quantidade)}</strong>
              <span>
                {diaMes(sel.data)} · {formatarMoeda(sel.valor)}
              </span>
            </>
          ) : (
            <>
              <strong>{qtdNfe(total)}</strong>
              <span>{formatarMoeda(valor)} no período</span>
            </>
          )}
        </div>
      </div>

      {total === 0 ? (
        <p className="grafico__vazio">Nenhuma NF-e emitida nos últimos 30 dias.</p>
      ) : (
        <>
          <div className="colunas" aria-hidden="true" onMouseLeave={() => setAtivo(null)}>
            <span className="colunas__max">{maximo}</span>
            {dias.map((d, i) => (
              <div
                key={d.data}
                className={`colunas__slot${ativo === i ? ' colunas__slot--ativo' : ''}`}
                onMouseEnter={() => setAtivo(i)}
              >
                <span
                  className="colunas__barra"
                  style={{ height: d.quantidade ? `${(d.quantidade / maximo) * 100}%` : 0 }}
                />
              </div>
            ))}
          </div>
          <div className="colunas__eixo" aria-hidden="true">
            <span>{diaMes(dias[0].data)}</span>
            <span>{diaMes(dias[Math.floor(dias.length / 2)].data)}</span>
            <span>Hoje</span>
          </div>
        </>
      )}

      {/* Visão em tabela para leitores de tela. */}
      <table className="sr-only">
        <caption>NF-e por dia de emissão, últimos 30 dias</caption>
        <thead>
          <tr>
            <th>Dia</th>
            <th>Quantidade</th>
            <th>Valor</th>
          </tr>
        </thead>
        <tbody>
          {dias.map((d) => (
            <tr key={d.data}>
              <td>{diaMes(d.data)}</td>
              <td>{d.quantidade}</td>
              <td>{formatarMoeda(d.valor)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/**
 * Barras horizontais com os 5 fornecedores de maior valor (últimos 30 dias).
 * O valor fica escrito na ponta de cada barra; o nome, acima dela.
 */
export function GraficoFornecedores({ fornecedores }: { fornecedores: Fornecedor[] }) {
  const maximo = Math.max(1, ...fornecedores.map((f) => f.valor))

  return (
    <div className="card grafico">
      <div className="grafico__topo">
        <div>
          <h2 className="grafico__titulo">Maiores fornecedores</h2>
          <p className="grafico__sub">Por valor · últimos 30 dias</p>
        </div>
      </div>

      {fornecedores.length === 0 ? (
        <p className="grafico__vazio">Sem notas de fornecedores no período.</p>
      ) : (
        <ol className="barras">
          {fornecedores.map((f) => (
            <li key={f.cnpj ?? f.nome} title={`${f.nome} · ${qtdNfe(f.quantidade)}`}>
              <div className="barras__nome">{f.nome}</div>
              <div className="barras__linha">
                <span className="barras__trilho">
                  <span
                    className="barras__barra"
                    style={{ width: `${Math.max(2, (f.valor / maximo) * 100)}%` }}
                  />
                </span>
                <span className="barras__valor">{formatarMoeda(f.valor)}</span>
              </div>
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}
