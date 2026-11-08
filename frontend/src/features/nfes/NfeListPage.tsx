import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { nfeApi, unidadesApi, mensagemDeErro, type FiltrosNfe, type OrdemNfe } from '../../lib/api'
import { formatarCnpj, formatarData, formatarMoeda } from '../../lib/format'
import { NfeBuscaForm } from './NfeBuscaForm'
import { ColunaOrdenavel } from '../../components/ui/ColunaOrdenavel'
import { proximaOrdem, type Ordem } from '../../lib/ordem'

/** Sentido inicial de cada coluna ao ser escolhida. */
const DIRECAO_INICIAL = { emissao: 'desc', emitente: 'asc', numero: 'desc', valor: 'desc' } as const

/**
 * Lista de NF-e recebidas, com filtros e exportação.
 *
 * Os filtros são os mesmos aceitos pela API (período, unidade, emitente, valor,
 * chave e busca textual). A exportação reutiliza os filtros aplicados no
 * momento. A NF-e pertence à unidade (CNPJ) que a recebeu, por isso cada linha
 * mostra a unidade e o detalhe abre a cópia daquela unidade.
 *
 * Os cabeçalhos de Emissão, Emitente, Número e Valor ordenam a lista (no
 * servidor, então vale para todas as páginas). A ordem é mantida ao filtrar.
 */
export function NfeListPage() {
  const navigate = useNavigate()

  const [filtros, setFiltros] = useState<FiltrosNfe>({})
  const [pagina, setPagina] = useState(1)
  const [ordem, setOrdem] = useState<Ordem<OrdemNfe>>({ campo: 'emissao', direcao: 'desc' })
  const [aviso, setAviso] = useState<string | null>(null)

  // Rascunho dos campos do formulário (aplicado ao clicar em "Filtrar").
  const [de, setDe] = useState('')
  const [ate, setAte] = useState('')
  const [unidadeId, setUnidadeId] = useState('')
  const [emitente, setEmitente] = useState('')
  const [q, setQ] = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['nfes', filtros, ordem, pagina],
    queryFn: () =>
      nfeApi.listar({ ...filtros, ordem: ordem.campo, direcao: ordem.direcao, page: pagina }),
  })

  const { data: unidades } = useQuery({
    queryKey: ['unidades', 'opcoes'],
    queryFn: () => unidadesApi.listar(100, 1),
  })

  const exportar = useMutation({
    mutationFn: ({ formato }: { formato: 'csv' | 'xlsx' }) => nfeApi.exportar(filtros, formato),
    onSuccess: (mensagem) => setAviso(mensagem),
    onError: (e) => setAviso(mensagemDeErro(e)),
  })

  const exportarXml = useMutation({
    mutationFn: () => nfeApi.exportarXml(filtros),
    onSuccess: (mensagem) => setAviso(mensagem),
    onError: (e) => setAviso(mensagemDeErro(e)),
  })

  function aplicar(evento: FormEvent) {
    evento.preventDefault()
    setFiltros({
      de: de || undefined,
      ate: ate || undefined,
      unidade_id: unidadeId ? Number(unidadeId) : undefined,
      emitente_cnpj: emitente || undefined,
      q: q || undefined,
    })
    setPagina(1)
  }

  function ordenar(campo: OrdemNfe) {
    setOrdem((atual) => proximaOrdem(atual, campo, DIRECAO_INICIAL[campo]))
    setPagina(1)
  }

  function limpar() {
    setDe('')
    setAte('')
    setUnidadeId('')
    setEmitente('')
    setQ('')
    setFiltros({})
    setPagina(1)
  }

  return (
    <div>
      <div className="pagina-topo">
        <h1>NF-e</h1>
        <NfeBuscaForm />
      </div>

      <form className="filtros" onSubmit={aplicar} noValidate>
        <div className="campo">
          <label htmlFor="de">De</label>
          <input id="de" type="date" value={de} onChange={(e) => setDe(e.target.value)} />
        </div>

        <div className="campo">
          <label htmlFor="ate">Até</label>
          <input id="ate" type="date" value={ate} onChange={(e) => setAte(e.target.value)} />
        </div>

        <div className="campo">
          <label htmlFor="unidade">Unidade</label>
          <select id="unidade" value={unidadeId} onChange={(e) => setUnidadeId(e.target.value)}>
            <option value="">Todas</option>
            {unidades?.data.map((u) => (
              <option key={u.id} value={u.id}>
                {u.nome_resumido || u.nome} ({formatarCnpj(u.cnpj)})
              </option>
            ))}
          </select>
        </div>

        <div className="campo">
          <label htmlFor="emitente">Emitente (CNPJ)</label>
          <input
            id="emitente"
            inputMode="numeric"
            maxLength={14}
            value={emitente}
            onChange={(e) => setEmitente(e.target.value.replace(/\D/g, ''))}
          />
        </div>

        <div className="campo">
          <label htmlFor="q">Busca</label>
          <input
            id="q"
            placeholder="Emitente, número ou chave"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
        </div>

        <button type="submit" className="botao">
          Filtrar
        </button>
        <button type="button" className="botao botao--secundario" onClick={limpar}>
          Limpar
        </button>
      </form>

      <div style={{ display: 'flex', gap: 'var(--esp-3)', marginBottom: 'var(--esp-4)' }}>
        <button
          type="button"
          className="botao botao--secundario"
          onClick={() => exportar.mutate({ formato: 'csv' })}
          disabled={exportar.isPending}
        >
          Exportar CSV
        </button>
        <button
          type="button"
          className="botao botao--secundario"
          onClick={() => exportar.mutate({ formato: 'xlsx' })}
          disabled={exportar.isPending}
        >
          Exportar XLSX
        </button>
        <button
          type="button"
          className="botao botao--secundario"
          onClick={() => exportarXml.mutate()}
          disabled={exportarXml.isPending}
        >
          Exportar XMLs (ZIP)
        </button>
      </div>

      {aviso && (
        <div className="alerta alerta--info" role="status">
          {aviso}
        </div>
      )}

      {isLoading && <div className="card">Carregando NF-e…</div>}
      {isError && <div className="alerta alerta--erro">Não foi possível carregar as NF-e.</div>}

      {data && (
        <div className="card" style={{ padding: 0 }}>
          <table className="tabela">
            <thead>
              <tr>
                <ColunaOrdenavel campo="emissao" rotulo="Emissão" ordem={ordem} onOrdenar={ordenar} />
                <th>Unidade</th>
                <ColunaOrdenavel campo="emitente" rotulo="Emitente" ordem={ordem} onOrdenar={ordenar} />
                <ColunaOrdenavel campo="numero" rotulo="Número" ordem={ordem} onOrdenar={ordenar} />
                <ColunaOrdenavel campo="valor" rotulo="Valor" ordem={ordem} onOrdenar={ordenar} />
                <th>Estado</th>
                <th>XML</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((nfe) => (
                <tr
                  key={nfe.id}
                  style={{ cursor: 'pointer' }}
                  onClick={() => navigate(`/nfes/${nfe.chave}?unidade=${nfe.unidade_id}`)}
                >
                  <td>{formatarData(nfe.data_emissao)}</td>
                  <td>
                    {nfe.unidade ? (
                      <>
                        <strong>{nfe.unidade.nome_resumido || nfe.unidade.nome}</strong>
                        <div className="shell__usuario">{formatarCnpj(nfe.unidade.cnpj)}</div>
                      </>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td>
                    <strong>{nfe.emitente_nome ?? '—'}</strong>
                    <div className="shell__usuario">{formatarCnpj(nfe.emitente_cnpj)}</div>
                  </td>
                  <td>
                    {nfe.numero ?? '—'}/{nfe.serie ?? '—'}
                  </td>
                  <td>{formatarMoeda(nfe.valor_total)}</td>
                  <td>
                    <span className={nfe.estado === 'completo' ? 'selo selo--ok' : 'selo selo--neutro'}>
                      {nfe.estado}
                    </span>
                  </td>
                  <td>{nfe.tem_xml ? 'sim' : '—'}</td>
                </tr>
              ))}
              {data.data.length === 0 && (
                <tr>
                  <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cor-texto-suave)' }}>
                    Nenhuma NF-e encontrada.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {data && data.meta.last_page > 1 && (
        <div className="paginacao">
          <button
            type="button"
            className="botao botao--secundario"
            disabled={pagina <= 1}
            onClick={() => setPagina((p) => p - 1)}
          >
            Anterior
          </button>
          <span>
            Página {data.meta.current_page} de {data.meta.last_page} ({data.meta.total} no total)
          </span>
          <button
            type="button"
            className="botao botao--secundario"
            disabled={pagina >= data.meta.last_page}
            onClick={() => setPagina((p) => p + 1)}
          >
            Próxima
          </button>
        </div>
      )}

      <p className="shell__usuario" style={{ marginTop: 'var(--esp-4)' }}>
        Exportações rodam em segundo plano. Ao concluir, você é avisado em{' '}
        <Link to="/notificacoes">Notificações</Link>.
      </p>
    </div>
  )
}