import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import {
  nfseApi,
  unidadesApi,
  mensagemDeErro,
  type FiltrosNfse,
  type PapelNfse,
  type SituacaoNfse,
} from '../../lib/api'
import {
  formatarCnpj,
  formatarCompetencia,
  formatarData,
  formatarDocumento,
  formatarMoeda,
} from '../../lib/format'
import { ROTULO_PAPEL, ROTULO_SITUACAO, classeSituacao } from './rotulos'
import { NfseBuscaForm } from './NfseBuscaForm'

/**
 * Lista de NFS-e recebidas pelo Ambiente de Dados Nacional.
 *
 * A fila do ADN traz as notas recebidas, emitidas e intermediadas pela
 * unidade; a tela abre em "Recebidas". O período pode valer para a emissão
 * ou para a competência. A exportação usa os filtros aplicados.
 */
export function NfseListPage() {
  const navigate = useNavigate()

  const [filtros, setFiltros] = useState<FiltrosNfse>({ papel: 'tomador' })
  const [pagina, setPagina] = useState(1)
  const [aviso, setAviso] = useState<string | null>(null)

  const [de, setDe] = useState('')
  const [ate, setAte] = useState('')
  const [campoData, setCampoData] = useState<'emissao' | 'competencia'>('emissao')
  const [unidadeId, setUnidadeId] = useState('')
  const [papel, setPapel] = useState<PapelNfse | ''>('tomador')
  const [situacao, setSituacao] = useState<SituacaoNfse | ''>('')
  const [q, setQ] = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['nfses', filtros, pagina],
    queryFn: () => nfseApi.listar({ ...filtros, page: pagina }),
  })

  const { data: unidades } = useQuery({
    queryKey: ['unidades', 'opcoes'],
    queryFn: () => unidadesApi.listar(100, 1),
  })

  const exportar = useMutation({
    mutationFn: ({ formato }: { formato: 'csv' | 'xlsx' }) => nfseApi.exportar(filtros, formato),
    onSuccess: (mensagem) => setAviso(mensagem),
    onError: (e) => setAviso(mensagemDeErro(e)),
  })

  const exportarXml = useMutation({
    mutationFn: () => nfseApi.exportarXml(filtros),
    onSuccess: (mensagem) => setAviso(mensagem),
    onError: (e) => setAviso(mensagemDeErro(e)),
  })

  function aplicar(evento: FormEvent) {
    evento.preventDefault()
    setFiltros({
      de: de || undefined,
      ate: ate || undefined,
      data: de || ate ? campoData : undefined,
      unidade_id: unidadeId ? Number(unidadeId) : undefined,
      papel: papel || undefined,
      situacao: situacao || undefined,
      q: q || undefined,
    })
    setPagina(1)
  }

  function limpar() {
    setDe('')
    setAte('')
    setCampoData('emissao')
    setUnidadeId('')
    setPapel('tomador')
    setSituacao('')
    setQ('')
    setFiltros({ papel: 'tomador' })
    setPagina(1)
  }

  return (
    <div>
      <div className="pagina-topo">
        <h1>NFS-e</h1>
        <NfseBuscaForm />
      </div>

      <form className="filtros" onSubmit={aplicar} noValidate>
        <div className="campo">
          <label htmlFor="papel">Notas</label>
          <select id="papel" value={papel} onChange={(e) => setPapel(e.target.value as PapelNfse | '')}>
            <option value="tomador">Recebidas</option>
            <option value="prestador">Emitidas</option>
            <option value="intermediario">Intermediadas</option>
            <option value="">Todas</option>
          </select>
        </div>

        <div className="campo">
          <label htmlFor="campo-data">Período por</label>
          <select
            id="campo-data"
            value={campoData}
            onChange={(e) => setCampoData(e.target.value as 'emissao' | 'competencia')}
          >
            <option value="emissao">Emissão</option>
            <option value="competencia">Competência</option>
          </select>
        </div>

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
          <label htmlFor="situacao">Situação</label>
          <select
            id="situacao"
            value={situacao}
            onChange={(e) => setSituacao(e.target.value as SituacaoNfse | '')}
          >
            <option value="">Todas</option>
            <option value="normal">Normal</option>
            <option value="cancelada">Cancelada</option>
            <option value="substituida">Substituída</option>
          </select>
        </div>

        <div className="campo">
          <label htmlFor="q">Busca</label>
          <input
            id="q"
            placeholder="Prestador, número, serviço ou chave"
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

      {isLoading && <div className="card">Carregando NFS-e…</div>}
      {isError && <div className="alerta alerta--erro">Não foi possível carregar as NFS-e.</div>}

      {data && (
        <div className="card" style={{ padding: 0 }}>
          <table className="tabela">
            <thead>
              <tr>
                <th>Emissão</th>
                <th>Competência</th>
                <th>Unidade</th>
                <th>Prestador</th>
                <th>Número</th>
                <th>Município</th>
                <th>Valor</th>
                <th>Situação</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((nfse) => (
                <tr
                  key={nfse.id}
                  style={{ cursor: 'pointer' }}
                  onClick={() => navigate(`/nfses/${nfse.chave}?unidade=${nfse.unidade_id}`)}
                >
                  <td>{formatarData(nfse.data_emissao)}</td>
                  <td>{formatarCompetencia(nfse.competencia)}</td>
                  <td>
                    {nfse.unidade ? (
                      <>
                        <strong>{nfse.unidade.nome_resumido || nfse.unidade.nome}</strong>
                        <div className="shell__usuario">{formatarCnpj(nfse.unidade.cnpj)}</div>
                      </>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td>
                    <strong>{nfse.prestador_nome ?? '—'}</strong>
                    <div className="shell__usuario">{formatarDocumento(nfse.prestador_documento)}</div>
                  </td>
                  <td>
                    {nfse.numero ?? '—'}
                    {filtros.papel === undefined && (
                      <div className="shell__usuario">{ROTULO_PAPEL[nfse.papel]}</div>
                    )}
                  </td>
                  <td>{nfse.municipio_emissor_nome ?? '—'}</td>
                  <td>
                    {formatarMoeda(nfse.valor_servico)}
                    {nfse.iss_retido && <div className="shell__usuario">ISS retido</div>}
                  </td>
                  <td>
                    <span className={classeSituacao(nfse.situacao)}>{ROTULO_SITUACAO[nfse.situacao]}</span>
                  </td>
                </tr>
              ))}
              {data.data.length === 0 && (
                <tr>
                  <td colSpan={8} style={{ textAlign: 'center', color: 'var(--cor-texto-suave)' }}>
                    Nenhuma NFS-e encontrada.
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
        As NFS-e chegam pelo Ambiente de Dados Nacional a cada 3 horas, em dias úteis. Notas de
        municípios que ainda não compartilham com o ambiente nacional não aparecem aqui.
        Exportações rodam em segundo plano e avisam em <Link to="/notificacoes">Notificações</Link>.
      </p>
    </div>
  )
}
