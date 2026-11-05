import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  mensagemDeErro,
  tenantsApi,
  usuariosApi,
  type NovoUsuario,
  type TenantResumo,
  type UsuarioAdmin,
} from '../../lib/api'
import { useSessao } from '../auth/session'

/** Rótulos em pt-BR dos quatro papéis aceitos pelo backend. */
const PAPEIS: Array<{ valor: string; rotulo: string }> = [
  { valor: 'super-admin', rotulo: 'Super-admin' },
  { valor: 'admin-tenant', rotulo: 'Administrador da entidade' },
  { valor: 'operador', rotulo: 'Operador' },
  { valor: 'consulta', rotulo: 'Consulta' },
]

/** Traduz o nome técnico do papel para o rótulo exibido. */
function rotularPapel(papel: string): string {
  return PAPEIS.find((p) => p.valor === papel)?.rotulo ?? papel
}

/**
 * Lista de usuários + criação/edição com papel e vínculo a entidades.
 *
 * A lista de entidades oferecidas depende do alcance do operador: super-admin
 * (`pode_todas`) vê todas via `tenantsApi.listar()`; os demais só as suas
 * (`tenants_disponiveis`). A própria conta não mostra ações sensíveis — editar
 * papel/vínculos ou desativar a si mesmo — porque o backend também bloqueia
 * (403). A autorização efetiva é sempre do servidor.
 */
export function UsuariosPage() {
  const { usuario } = useSessao()
  const [pagina, setPagina] = useState(1)
  const [formAberto, setFormAberto] = useState(false)
  const [editando, setEditando] = useState<UsuarioAdmin | null>(null)

  const podeTodas = usuario?.pode_todas ?? false
  const meuId = usuario?.id ?? null

  const { data, isLoading, isError } = useQuery({
    queryKey: ['usuarios', pagina],
    queryFn: () => usuariosApi.listar(50, pagina),
  })

  const { data: entidades } = useQuery({
    queryKey: ['tenants', 'todas'],
    // 100 = teto do `per_page` no backend (TenantController::index). Cobre a
    // lista de entidades oferecidas no formulário de usuário do super-admin.
    queryFn: () => tenantsApi.listar(100, 1),
    enabled: podeTodas,
  })

  const disponiveis: TenantResumo[] = podeTodas
    ? (entidades?.data ?? []).map((t) => ({ id: t.id, nome: t.nome }))
    : (usuario?.tenants_disponiveis ?? [])

  return (
    <div>
      <div className="pagina-topo">
        <h1>Usuários</h1>
        <button
          type="button"
          className="botao"
          onClick={() => {
            setEditando(null)
            setFormAberto((v) => !v)
          }}
        >
          {formAberto && !editando ? 'Fechar' : 'Novo usuário'}
        </button>
      </div>

      {formAberto && (
        <UsuarioForm
          key={editando?.id ?? 'novo'}
          usuarioAlvo={editando}
          entidadesDisponiveis={disponiveis}
          podeTodas={podeTodas}
          onConcluido={() => {
            setFormAberto(false)
            setEditando(null)
            setPagina(1)
          }}
          onCancelar={() => {
            setFormAberto(false)
            setEditando(null)
          }}
        />
      )}

      {isLoading && <div className="card">Carregando usuários…</div>}
      {isError && (
        <div className="alerta alerta--erro">Não foi possível carregar os usuários.</div>
      )}

      {data && (
        <div className="card" style={{ padding: 0 }}>
          <table className="tabela">
            <thead>
              <tr>
                <th>Nome</th>
                <th>E-mail</th>
                <th>Papéis</th>
                <th>Entidades</th>
                <th>Ativo</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data.data.map((u) => {
                const ehPropriaConta = meuId !== null && u.id === meuId
                return (
                  <tr key={u.id}>
                    <td>
                      <strong>{u.name}</strong>
                    </td>
                    <td>{u.email}</td>
                    <td>{u.papeis.map(rotularPapel).join(', ') || '—'}</td>
                    <td>
                      {u.tenants.length > 0
                        ? u.tenants.map((t) => t.nome).join(', ')
                        : '—'}
                    </td>
                    <td>
                      {u.ativo ? (
                        <span className="selo selo--ok">ativo</span>
                      ) : (
                        <span className="selo selo--neutro">inativo</span>
                      )}
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      {ehPropriaConta ? (
                        <span style={{ color: 'var(--cor-texto-suave)' }}>Sua conta</span>
                      ) : (
                        <div
                          style={{
                            display: 'flex',
                            gap: '0.5rem',
                            justifyContent: 'flex-end',
                            alignItems: 'center',
                          }}
                        >
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
                          <BotaoAtivo usuario={u} />
                        </div>
                      )}
                    </td>
                  </tr>
                )
              })}
              {data.data.length === 0 && (
                <tr>
                  <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cor-texto-suave)' }}>
                    Nenhum usuário cadastrado.
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

/** Ação de ativar/desativar um usuário, com confirmação antes da mudança. */
function BotaoAtivo({ usuario }: { usuario: UsuarioAdmin }) {
  const queryClient = useQueryClient()
  const [erro, setErro] = useState<string | null>(null)

  const alternar = useMutation({
    mutationFn: (ativo: boolean) => usuariosApi.definirAtivo(usuario.id, ativo),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['usuarios'] })
    },
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  function aoClicar() {
    setErro(null)
    const acao = usuario.ativo ? 'desativar' : 'ativar'
    if (window.confirm(`Deseja ${acao} o usuário "${usuario.name}"?`)) {
      alternar.mutate(!usuario.ativo)
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
        {usuario.ativo ? 'Desativar' : 'Ativar'}
      </button>
      {erro && (
        <span className="alerta alerta--erro" role="alert">
          {erro}
        </span>
      )}
    </>
  )
}

/** Formulário de criação/edição de usuário. */
function UsuarioForm({
  usuarioAlvo,
  entidadesDisponiveis,
  podeTodas,
  onConcluido,
  onCancelar,
}: {
  usuarioAlvo: UsuarioAdmin | null
  entidadesDisponiveis: TenantResumo[]
  podeTodas: boolean
  onConcluido: () => void
  onCancelar: () => void
}) {
  const queryClient = useQueryClient()
  const editando = Boolean(usuarioAlvo)

  const [nome, setNome] = useState(usuarioAlvo?.name ?? '')
  const [email, setEmail] = useState(usuarioAlvo?.email ?? '')
  const [senha, setSenha] = useState('')
  const [papel, setPapel] = useState(usuarioAlvo?.papeis[0] ?? 'operador')
  const [tenants, setTenants] = useState<number[]>(
    usuarioAlvo ? usuarioAlvo.tenants.map((t) => t.id) : [],
  )
  const [erro, setErro] = useState<string | null>(null)

  // Não-super-admin nunca pode criar/atribuir super-admin (o backend devolve
  // 403): escondemos a opção para não oferecer uma ação que seria negada.
  const papeisOferecidos = podeTodas
    ? PAPEIS
    : PAPEIS.filter((p) => p.valor !== 'super-admin')

  const salvar = useMutation({
    mutationFn: (payload: NovoUsuario) =>
      usuarioAlvo ? usuariosApi.atualizar(usuarioAlvo.id, payload) : usuariosApi.criar(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['usuarios'] })
      onConcluido()
    },
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  function aoAlternarEntidade(id: number) {
    setTenants((atual) =>
      atual.includes(id) ? atual.filter((t) => t !== id) : [...atual, id],
    )
  }

  function aoEnviar(evento: FormEvent) {
    evento.preventDefault()
    setErro(null)

    // Na criação a senha é obrigatória (mínimo 8); na edição é opcional.
    if (!editando && senha.length < 8) {
      setErro('A senha deve ter ao menos 8 caracteres.')
      return
    }

    salvar.mutate({
      name: nome,
      email,
      password: senha || undefined,
      papel,
      tenants,
    })
  }

  return (
    <div className="card">
      <h2 style={{ marginTop: 0 }}>{editando ? 'Editar usuário' : 'Novo usuário'}</h2>

      {erro && (
        <div className="alerta alerta--erro" role="alert">
          {erro}
        </div>
      )}

      <form onSubmit={aoEnviar} noValidate className="filtros">
        <div className="campo">
          <label htmlFor="name">Nome</label>
          <input id="name" value={nome} onChange={(e) => setNome(e.target.value)} required />
        </div>

        <div className="campo">
          <label htmlFor="email">E-mail</label>
          <input
            id="email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </div>

        <div className="campo">
          <label htmlFor="password">
            {editando ? 'Nova senha (opcional)' : 'Senha (mínimo 8)'}
          </label>
          <input
            id="password"
            type="password"
            value={senha}
            onChange={(e) => setSenha(e.target.value)}
            required={!editando}
          />
        </div>

        <div className="campo">
          <label htmlFor="papel">Papel</label>
          <select id="papel" value={papel} onChange={(e) => setPapel(e.target.value)}>
            {papeisOferecidos.map((p) => (
              <option key={p.valor} value={p.valor}>
                {p.rotulo}
              </option>
            ))}
          </select>
        </div>

        <fieldset className="campo" style={{ border: 0, padding: 0, margin: 0 }}>
          <legend style={{ marginBottom: '0.25rem' }}>Entidades</legend>
          {entidadesDisponiveis.length === 0 ? (
            <p style={{ color: 'var(--cor-texto-suave)', margin: 0 }}>
              Nenhuma entidade disponível.
            </p>
          ) : (
            entidadesDisponiveis.map((t) => (
              <label
                key={t.id}
                style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}
              >
                <input
                  type="checkbox"
                  checked={tenants.includes(t.id)}
                  onChange={() => aoAlternarEntidade(t.id)}
                />
                {t.nome}
              </label>
            ))
          )}
        </fieldset>

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