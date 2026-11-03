import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { mensagemDeErro, unidadesApi, type NovaUnidade, type Unidade } from '../../lib/api'
import { formatarCnpj, rotularAmbiente } from '../../lib/format'

/**
 * Lista de unidades gestoras da entidade + formulário de criação.
 *
 * A listagem é paginada (50 por página) e mostra o status do certificado de
 * cada unidade. Só quem tem `unidade.gerenciar` vê o formulário de criação.
 */
export function UnidadesPage() {
  const [pagina, setPagina] = useState(1)
  const [formAberto, setFormAberto] = useState(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['unidades', pagina],
    queryFn: () => unidadesApi.listar(50, pagina),
  })

  return (
    <div>
      <div className="pagina-topo">
        <h1>Unidades</h1>
        <button type="button" className="botao" onClick={() => setFormAberto((v) => !v)}>
          {formAberto ? 'Fechar' : 'Nova unidade'}
        </button>
      </div>

      {formAberto && (
        <NovaUnidadeForm
          onCriada={() => {
            setFormAberto(false)
            setPagina(1)
          }}
        />
      )}

      {isLoading && <div className="card">Carregando unidades…</div>}
      {isError && <div className="alerta alerta--erro">Não foi possível carregar as unidades.</div>}

      {data && (
        <div className="card" style={{ padding: 0 }}>
          <table className="tabela">
            <thead>
              <tr>
                <th>Nome</th>
                <th>CNPJ</th>
                <th>UF</th>
                <th>Ambiente</th>
                <th>Certificado</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data.data.map((u) => (
                <tr key={u.id}>
                  <td>
                    <strong>{u.nome_resumido || u.nome}</strong>
                  </td>
                  <td>{formatarCnpj(u.cnpj)}</td>
                  <td>{u.uf}</td>
                  <td>{rotularAmbiente(u.tp_amb)}</td>
                  <td>
                    <StatusCertificado unidade={u} />
                  </td>
                  <td style={{ textAlign: 'right' }}>
                    <Link to={`/unidades/${u.id}/certificado`}>Certificado</Link>
                  </td>
                </tr>
              ))}
              {data.data.length === 0 && (
                <tr>
                  <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cor-texto-suave)' }}>
                    Nenhuma unidade cadastrada.
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
            Página {data.meta.current_page} de {data.meta.last_page}
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
    </div>
  )
}

/** Selo com o status do certificado da unidade. */
function StatusCertificado({ unidade }: { unidade: Unidade }) {
  const c = unidade.certificado

  if (!c) return <span className="selo selo--erro">sem certificado</span>
  if (c.vencido) return <span className="selo selo--erro">vencido</span>
  if (c.dias_restantes <= 30) {
    return <span className="selo selo--alerta">{c.dias_restantes} dias</span>
  }
  return <span className="selo selo--ok">{c.dias_restantes} dias</span>
}

/** Formulário de criação de unidade. */
function NovaUnidadeForm({ onCriada }: { onCriada: () => void }) {
  const queryClient = useQueryClient()

  const [nome, setNome] = useState('')
  const [nomeResumido, setNomeResumido] = useState('')
  const [cnpj, setCnpj] = useState('')
  const [uf, setUf] = useState('')
  const [tpAmb, setTpAmb] = useState<1 | 2>(2)
  const [erro, setErro] = useState<string | null>(null)

  const criar = useMutation({
    mutationFn: (payload: NovaUnidade) => unidadesApi.criar(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['unidades'] })
      onCriada()
    },
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  function aoEnviar(evento: FormEvent) {
    evento.preventDefault()
    setErro(null)
    criar.mutate({
      nome,
      nome_resumido: nomeResumido || undefined,
      cnpj: cnpj.replace(/\D/g, ''),
      uf: uf.toUpperCase(),
      tp_amb: tpAmb,
    })
  }

  return (
    <div className="card">
      <h2 style={{ marginTop: 0 }}>Nova unidade</h2>

      {erro && (
        <div className="alerta alerta--erro" role="alert">
          {erro}
        </div>
      )}

      <form onSubmit={aoEnviar} noValidate className="filtros">
        <div className="campo">
          <label htmlFor="nome">Nome</label>
          <input id="nome" value={nome} onChange={(e) => setNome(e.target.value)} required />
        </div>

        <div className="campo">
          <label htmlFor="nome_resumido">Nome resumido</label>
          <input
            id="nome_resumido"
            value={nomeResumido}
            onChange={(e) => setNomeResumido(e.target.value)}
          />
        </div>

        <div className="campo">
          <label htmlFor="cnpj">CNPJ (14 dígitos)</label>
          <input
            id="cnpj"
            inputMode="numeric"
            maxLength={14}
            value={cnpj}
            onChange={(e) => setCnpj(e.target.value.replace(/\D/g, ''))}
            required
          />
        </div>

        <div className="campo">
          <label htmlFor="uf">UF</label>
          <input
            id="uf"
            maxLength={2}
            value={uf}
            onChange={(e) => setUf(e.target.value.replace(/[^a-zA-Z]/g, ''))}
            required
          />
        </div>

        <div className="campo">
          <label htmlFor="tp_amb">Ambiente</label>
          <select
            id="tp_amb"
            value={tpAmb}
            onChange={(e) => setTpAmb(Number(e.target.value) as 1 | 2)}
          >
            <option value={2}>Homologação</option>
            <option value={1}>Produção</option>
          </select>
        </div>

        <button type="submit" className="botao" disabled={criar.isPending}>
          {criar.isPending ? 'Salvando…' : 'Salvar'}
        </button>
      </form>
    </div>
  )
}