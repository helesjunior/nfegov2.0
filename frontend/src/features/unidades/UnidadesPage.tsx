import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ehErroDeStepUp, mensagemDeErro, unidadesApi, type NovaUnidade, type Unidade } from '../../lib/api'
import { formatarCnpj, rotularAmbiente } from '../../lib/format'
import { usePermissao } from '../auth/session'
import { useStepUp, type Rascunho } from '../auth/stepUp'

/**
 * Lista de unidades gestoras da entidade + criação/edição.
 *
 * A listagem é paginada (50 por página) e mostra o status do certificado de
 * cada unidade. Cada linha permite editar a unidade ou ativá-la/desativá-la.
 * A entidade (`tenant_id`) da unidade é fixa — nunca é editável aqui.
 *
 * Incluir, editar e ativar/desativar exigem `unidade.gerenciar` (admin): o
 * operador só vê a lista e o certificado (que ele troca quando vencer).
 */
export function UnidadesPage() {
  const queryClient = useQueryClient()
  const podeGerenciar = usePermissao('unidade.gerenciar')
  const { consumirRascunho, foiRenovado } = useStepUp()
  const [pagina, setPagina] = useState(1)
  const [formAberto, setFormAberto] = useState(false)
  const [editando, setEditando] = useState<Unidade | null>(null)
  // Rascunho restaurado após um step-up (form reaberto e preenchido).
  const [rascunho, setRascunho] = useState<Rascunho | null>(null)

  // Ao montar, retoma um rascunho deixado por um desafio de step-up. O consumo
  // acontece em efeito (não no initializer) por causa do StrictMode; aqui
  // sincronizamos com o singleton externo (falso positivo da regra).
  useEffect(() => {
    if (!podeGerenciar) return
    const pendente = consumirRascunho('unidade')
    if (!pendente) return

    // oxlint-disable-next-line react/set-state-in-effect
    setRascunho(pendente)
    setEditando(
      pendente.modo === 'editar' && pendente.id
        ? (buscarUnidadeNoCache(queryClient, pendente.id) ?? null)
        : null,
    )
    setFormAberto(true)
  }, [consumirRascunho, queryClient, podeGerenciar])

  const { data, isLoading, isError } = useQuery({
    queryKey: ['unidades', pagina],
    queryFn: () => unidadesApi.listar(50, pagina),
  })

  return (
    <div>
      {foiRenovado && (
        <div className="alerta alerta--info" role="status">
          Verificação de segurança renovada. Confira os dados e clique em Salvar para concluir.
        </div>
      )}

      <div className="pagina-topo">
        <h1>Unidades</h1>
        {podeGerenciar && (
          <button
            type="button"
            className="botao"
            onClick={() => {
              setEditando(null)
              setRascunho(null)
              setFormAberto((v) => !v)
            }}
          >
            {formAberto && !editando ? 'Fechar' : 'Nova unidade'}
          </button>
        )}
      </div>

      {podeGerenciar && formAberto && (
        <NovaUnidadeForm
          key={editando?.id ?? 'novo'}
          unidadeEmEdicao={editando ?? undefined}
          rascunhoInicial={rascunho ?? undefined}
          onConcluida={() => {
            setFormAberto(false)
            setEditando(null)
            setRascunho(null)
            setPagina(1)
          }}
          onCancelar={() => {
            setFormAberto(false)
            setEditando(null)
            setRascunho(null)
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
                    <div
                      style={{
                        display: 'flex',
                        gap: '0.5rem',
                        justifyContent: 'flex-end',
                        alignItems: 'center',
                      }}
                    >
                      <Link to={`/unidades/${u.id}/certificado`}>Certificado</Link>
                      {podeGerenciar && (
                        <>
                          <button
                            type="button"
                            className="botao botao--secundario"
                            onClick={() => {
                              setEditando(u)
                              setFormAberto(true)
                            }}
                          >
                            Editar
                          </button>
                          <BotaoAtivo unidade={u} />
                        </>
                      )}
                    </div>
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

/**
 * Recupera a unidade de uma página já carregada no cache do react-query.
 *
 * Usado ao restaurar um rascunho de edição; se não achar, o formulário abre no
 * modo criação com os dados do rascunho.
 */
function buscarUnidadeNoCache(
  queryClient: ReturnType<typeof useQueryClient>,
  id: number,
): Unidade | null {
  const entradas = queryClient.getQueriesData<{ data: Unidade[] }>({ queryKey: ['unidades'] })
  for (const [, valor] of entradas) {
    const achado = valor?.data.find((u) => u.id === id)
    if (achado) return achado
  }
  return null
}

/** Ação de ativar/desativar uma unidade, com confirmação antes da mudança. */
function BotaoAtivo({ unidade }: { unidade: Unidade }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { desafiar } = useStepUp()
  const [erro, setErro] = useState<string | null>(null)

  const alternar = useMutation({
    mutationFn: (ativo: boolean) => unidadesApi.definirAtivo(unidade.id, ativo),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['unidades'] })
    },
    onError: (e) => {
      if (ehErroDeStepUp(e)) {
        desafiar('/unidades')
        navigate('/mfa')
        return
      }
      setErro(mensagemDeErro(e))
    },
  })

  function aoClicar() {
    setErro(null)
    const acao = unidade.ativo ? 'desativar' : 'ativar'
    if (window.confirm(`Deseja ${acao} a unidade "${unidade.nome}"?`)) {
      alternar.mutate(!unidade.ativo)
    }
  }

  return (
    <>
      <button
        type="button"
        className="botao botao--secundario"
        onClick={aoClicar}
        disabled={alternar.isPending}
      >
        {unidade.ativo ? 'Desativar' : 'Ativar'}
      </button>
      {erro && (
        <span className="alerta alerta--erro" role="alert">
          {erro}
        </span>
      )}
    </>
  )
}

/**
 * Formulário de criação/edição de unidade.
 *
 * Em modo edição (`unidadeEmEdicao`) pré-preenche os campos e chama `atualizar`.
 * A entidade (`tenant_id`) da unidade é fixa e não muda: por isso o formulário
 * nunca oferece um seletor de entidade, nem na criação nem na edição.
 */
function NovaUnidadeForm({
  unidadeEmEdicao,
  rascunhoInicial,
  onConcluida,
  onCancelar,
}: {
  unidadeEmEdicao?: Unidade
  rascunhoInicial?: Rascunho
  onConcluida: () => void
  onCancelar: () => void
}) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { desafiar } = useStepUp()
  const editando = Boolean(unidadeEmEdicao)

  // O rascunho (quando existe) tem precedência sobre os valores salvos: ele
  // representa o que o usuário tinha digitado antes do desafio de step-up.
  const d = rascunhoInicial?.dados ?? {}
  const [nome, setNome] = useState((d.nome as string) ?? unidadeEmEdicao?.nome ?? '')
  const [nomeResumido, setNomeResumido] = useState(
    (d.nome_resumido as string) ?? unidadeEmEdicao?.nome_resumido ?? '',
  )
  const [cnpj, setCnpj] = useState((d.cnpj as string) ?? unidadeEmEdicao?.cnpj ?? '')
  const [uf, setUf] = useState((d.uf as string) ?? unidadeEmEdicao?.uf ?? '')
  const [tpAmb, setTpAmb] = useState<1 | 2>(
    (d.tp_amb as 1 | 2) ?? (unidadeEmEdicao?.tp_amb === 1 ? 1 : 2),
  )
  const [erro, setErro] = useState<string | null>(null)

  const salvar = useMutation({
    mutationFn: (payload: NovaUnidade) =>
      unidadeEmEdicao
        ? unidadesApi.atualizar(unidadeEmEdicao.id, payload)
        : unidadesApi.criar(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['unidades'] })
      onConcluida()
    },
    onError: (e) => {
      if (ehErroDeStepUp(e)) {
        desafiar('/unidades', {
          origem: 'unidade',
          modo: editando ? 'editar' : 'criar',
          id: unidadeEmEdicao?.id,
          dados: { nome, nome_resumido: nomeResumido, cnpj, uf, tp_amb: tpAmb },
        })
        navigate('/mfa')
        return
      }
      setErro(mensagemDeErro(e))
    },
  })

  function aoEnviar(evento: FormEvent) {
    evento.preventDefault()
    setErro(null)
    salvar.mutate({
      nome,
      nome_resumido: nomeResumido || undefined,
      cnpj: cnpj.replace(/\D/g, ''),
      uf: uf.toUpperCase(),
      tp_amb: tpAmb,
    })
  }

  return (
    <div className="card">
      <h2 style={{ marginTop: 0 }}>{editando ? 'Editar unidade' : 'Nova unidade'}</h2>

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

        <button type="submit" className="botao" disabled={salvar.isPending}>
          {salvar.isPending ? 'Salvando…' : 'Salvar'}
        </button>
        {editando && (
          <button
            type="button"
            className="botao botao--secundario"
            onClick={onCancelar}
            disabled={salvar.isPending}
          >
            Cancelar
          </button>
        )}
      </form>
    </div>
  )
}