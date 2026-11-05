import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ehErroDeStepUp, mensagemDeErro, tenantsApi, type NovoTenant, type Tenant } from '../../lib/api'
import { formatarCnpj } from '../../lib/format'
import { useStepUp, type Rascunho } from '../auth/stepUp'

/**
 * Lista de entidades (tenants) + criação, edição e ativação/desativação.
 *
 * Restrita a super-admin na navegação (item de menu só aparece quando
 * `usuario.pode_todas`); a autorização efetiva é do backend.
 */
export function TenantsPage() {
  const queryClient = useQueryClient()
  const { consumirRascunho, foiRenovado } = useStepUp()
  const [pagina, setPagina] = useState(1)
  const [formAberto, setFormAberto] = useState(false)
  const [editando, setEditando] = useState<Tenant | null>(null)
  // Rascunho restaurado após um step-up (form reaberto e preenchido).
  const [rascunho, setRascunho] = useState<Rascunho | null>(null)

  // Ao montar, retoma um rascunho deixado por um desafio de step-up. O consumo
  // acontece em efeito (não no initializer) por causa do StrictMode; aqui
  // sincronizamos com o singleton externo (falso positivo da regra).
  useEffect(() => {
    const pendente = consumirRascunho('tenant')
    if (!pendente) return

    // oxlint-disable-next-line react/set-state-in-effect
    setRascunho(pendente)
    setEditando(
      pendente.modo === 'editar' && pendente.id
        ? (buscarTenantNoCache(queryClient, pendente.id) ?? null)
        : null,
    )
    setFormAberto(true)
  }, [consumirRascunho, queryClient])

  const { data, isLoading, isError } = useQuery({
    queryKey: ['tenants', pagina],
    queryFn: () => tenantsApi.listar(50, pagina),
  })

  return (
    <div>
      {foiRenovado && (
        <div className="alerta alerta--info" role="status">
          Verificação de segurança renovada. Confira os dados e clique em Salvar para concluir.
        </div>
      )}

      <div className="pagina-topo">
        <h1>Entidades</h1>
        <button
          type="button"
          className="botao"
          onClick={() => {
            setEditando(null)
            setRascunho(null)
            setFormAberto((v) => !v)
          }}
        >
          {formAberto && !editando ? 'Fechar' : 'Nova entidade'}
        </button>
      </div>

      {formAberto && (
        <TenantForm
          key={editando?.id ?? 'novo'}
          tenant={editando}
          rascunhoInicial={rascunho ?? undefined}
          onConcluido={() => {
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

      {isLoading && <div className="card">Carregando entidades…</div>}
      {isError && (
        <div className="alerta alerta--erro">Não foi possível carregar as entidades.</div>
      )}

      {data && (
        <div className="card" style={{ padding: 0 }}>
          <table className="tabela">
            <thead>
              <tr>
                <th>Nome</th>
                <th>CNPJ</th>
                <th>Ativo</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data.data.map((t) => (
                <tr key={t.id}>
                  <td>
                    <strong>{t.nome}</strong>
                  </td>
                  <td>{formatarCnpj(t.cnpj)}</td>
                  <td>
                    {t.ativo ? (
                      <span className="selo selo--ok">ativa</span>
                    ) : (
                      <span className="selo selo--neutro">inativa</span>
                    )}
                  </td>
                  <td style={{ textAlign: 'right' }}>
                    <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end', alignItems: 'center' }}>
                      <button
                        type="button"
                        className="botao botao--secundario"
                        onClick={() => {
                          setEditando(t)
                          setFormAberto(true)
                        }}
                      >
                        Editar
                      </button>
                      <BotaoAtivo tenant={t} />
                    </div>
                  </td>
                </tr>
              ))}
              {data.data.length === 0 && (
                <tr>
                  <td colSpan={4} style={{ textAlign: 'center', color: 'var(--cor-texto-suave)' }}>
                    Nenhuma entidade cadastrada.
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

/**
 * Recupera a entidade de uma página já carregada no cache do react-query.
 *
 * Usado ao restaurar um rascunho de edição: a lista pode estar em qualquer
 * página; varremos as entradas em cache. Se não achar, o formulário abre no
 * modo criação com os dados do rascunho.
 */
function buscarTenantNoCache(
  queryClient: ReturnType<typeof useQueryClient>,
  id: number,
): Tenant | null {
  const entradas = queryClient.getQueriesData<{ data: Tenant[] }>({ queryKey: ['tenants'] })
  for (const [, valor] of entradas) {
    const achado = valor?.data.find((t) => t.id === id)
    if (achado) return achado
  }
  return null
}

/** Ação de ativar/desativar uma entidade, com confirmação antes da mudança. */
function BotaoAtivo({ tenant }: { tenant: Tenant }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { desafiar } = useStepUp()
  const [erro, setErro] = useState<string | null>(null)

  const alternar = useMutation({
    mutationFn: (ativo: boolean) => tenantsApi.definirAtivo(tenant.id, ativo),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tenants'] })
    },
    onError: (e) => {
      if (ehErroDeStepUp(e)) {
        desafiar('/entidades')
        navigate('/mfa')
        return
      }
      setErro(mensagemDeErro(e))
    },
  })

  function aoClicar() {
    setErro(null)
    const acao = tenant.ativo ? 'desativar' : 'ativar'
    if (window.confirm(`Deseja ${acao} a entidade "${tenant.nome}"?`)) {
      alternar.mutate(!tenant.ativo)
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
        {tenant.ativo ? 'Desativar' : 'Ativar'}
      </button>
      {erro && (
        <span className="alerta alerta--erro" role="alert">
          {erro}
        </span>
      )}
    </>
  )
}

/** Formulário de criação/edição de entidade. */
function TenantForm({
  tenant,
  rascunhoInicial,
  onConcluido,
  onCancelar,
}: {
  tenant: Tenant | null
  rascunhoInicial?: Rascunho
  onConcluido: () => void
  onCancelar: () => void
}) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { desafiar } = useStepUp()
  const editando = Boolean(tenant)

  // O rascunho (quando existe) tem precedência sobre os valores salvos: ele
  // representa o que o usuário tinha digitado antes do desafio de step-up.
  const d = rascunhoInicial?.dados ?? {}
  const [nome, setNome] = useState((d.nome as string) ?? tenant?.nome ?? '')
  const [cnpj, setCnpj] = useState((d.cnpj as string) ?? tenant?.cnpj ?? '')
  const [ativo, setAtivo] = useState((d.ativo as boolean) ?? tenant?.ativo ?? true)
  const [erro, setErro] = useState<string | null>(null)

  const salvar = useMutation({
    mutationFn: (payload: NovoTenant) =>
      tenant ? tenantsApi.atualizar(tenant.id, payload) : tenantsApi.criar(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tenants'] })
      onConcluido()
    },
    onError: (e) => {
      if (ehErroDeStepUp(e)) {
        desafiar('/entidades', {
          origem: 'tenant',
          modo: editando ? 'editar' : 'criar',
          id: tenant?.id,
          dados: { nome, cnpj, ativo },
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
    const digitos = cnpj.replace(/\D/g, '')
    salvar.mutate({
      nome,
      cnpj: digitos || undefined,
      // `ativo` só é editável na criação; na edição a situação é alterada pela
      // ação dedicada (BotaoAtivo → PUT /tenants/{id}/ativo).
      ...(editando ? {} : { ativo }),
    })
  }

  return (
    <div className="card">
      <h2 style={{ marginTop: 0 }}>{editando ? 'Editar entidade' : 'Nova entidade'}</h2>

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
          <label htmlFor="cnpj">CNPJ (14 dígitos)</label>
          <input
            id="cnpj"
            inputMode="numeric"
            maxLength={14}
            value={cnpj}
            onChange={(e) => setCnpj(e.target.value.replace(/\D/g, ''))}
          />
        </div>

        {!editando && (
          <div className="campo">
            <label htmlFor="ativo">Situação</label>
            <select id="ativo" value={ativo ? '1' : '0'} onChange={(e) => setAtivo(e.target.value === '1')}>
              <option value="1">Ativa</option>
              <option value="0">Inativa</option>
            </select>
          </div>
        )}

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