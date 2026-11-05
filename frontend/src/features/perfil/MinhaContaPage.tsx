import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { mfaApi, perfilApi, mensagemDeErro } from '../../lib/api'
import { useSessao } from '../auth/session'

/**
 * "Minha conta": o usuário logado edita os próprios dados e escolhe o MFA.
 *
 * Três seções:
 * - Dados pessoais: nome e e-mail. Trocar o e-mail exige a senha atual.
 * - Senha: nova senha confirmada, exigindo a senha atual.
 * - Segurança: status/gestão do MFA (opcional apenas para quem não é obrigado).
 */
export function MinhaContaPage() {
  const queryClient = useQueryClient()
  const { usuario, recarregar } = useSessao()

  const [name, setName] = useState(usuario?.name ?? '')
  const [email, setEmail] = useState(usuario?.email ?? '')
  const [senhaAtualPerfil, setSenhaAtualPerfil] = useState('')

  const [senhaAtual, setSenhaAtual] = useState('')
  const [novaSenha, setNovaSenha] = useState('')
  const [confirmacao, setConfirmacao] = useState('')

  const [codigoDesabilitar, setCodigoDesabilitar] = useState('')
  const [mostrarCodigo, setMostrarCodigo] = useState(false)
  const [codigoAtivar, setCodigoAtivar] = useState('')

  const [erro, setErro] = useState<string | null>(null)
  const [sucesso, setSucesso] = useState<string | null>(null)

  if (!usuario) return null

  const emailMudou = email !== usuario.email

  async function aposSucesso(mensagem: string) {
    setSucesso(mensagem)
    setErro(null)
    await queryClient.invalidateQueries({ queryKey: ['sessao'] })
    await recarregar()
  }

  const salvarPerfil = useMutation({
    mutationFn: () =>
      perfilApi.atualizar({
        name,
        email,
        ...(emailMudou ? { current_password: senhaAtualPerfil } : {}),
      }),
    onSuccess: () => {
      setSenhaAtualPerfil('')
      return aposSucesso('Dados pessoais atualizados.')
    },
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  const trocarSenha = useMutation({
    mutationFn: () =>
      perfilApi.trocarSenha({
        current_password: senhaAtual,
        password: novaSenha,
        password_confirmation: confirmacao,
      }),
    onSuccess: () => {
      setSenhaAtual('')
      setNovaSenha('')
      setConfirmacao('')
      return aposSucesso('Senha alterada com sucesso.')
    },
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  const setupMfa = useMutation({
    mutationFn: () => mfaApi.setup(),
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  const confirmarMfa = useMutation({
    mutationFn: () => mfaApi.confirm(codigoAtivar),
    onSuccess: () => {
      setCodigoAtivar('')
      return aposSucesso('MFA ativado com sucesso.')
    },
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  const desabilitarMfa = useMutation({
    mutationFn: () => perfilApi.desabilitarMfa(codigoDesabilitar),
    onSuccess: () => {
      setCodigoDesabilitar('')
      setMostrarCodigo(false)
      return aposSucesso('MFA desabilitado.')
    },
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  function aoSalvarPerfil(evento: FormEvent) {
    evento.preventDefault()
    setSucesso(null)
    salvarPerfil.mutate()
  }

  function aoTrocarSenha(evento: FormEvent) {
    evento.preventDefault()
    setSucesso(null)
    trocarSenha.mutate()
  }

  return (
    <div className="pagina">
      <h1>Minha conta</h1>

      {erro && (
        <div className="alerta alerta--erro" role="alert">
          {erro}
        </div>
      )}
      {sucesso && (
        <div className="alerta alerta--info" role="status">
          {sucesso}
        </div>
      )}

      <section className="card">
        <h2>Dados pessoais</h2>
        <form onSubmit={aoSalvarPerfil} noValidate>
          <div className="campo">
            <label htmlFor="perfil-nome">Nome</label>
            <input
              id="perfil-nome"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
          </div>

          <div className="campo">
            <label htmlFor="perfil-email">E-mail</label>
            <input
              id="perfil-email"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </div>

          {emailMudou && (
            <div className="campo">
              <label htmlFor="perfil-senha-atual">Senha atual</label>
              <input
                id="perfil-senha-atual"
                type="password"
                autoComplete="current-password"
                value={senhaAtualPerfil}
                onChange={(e) => setSenhaAtualPerfil(e.target.value)}
                required
              />
            </div>
          )}

          <button type="submit" className="botao" disabled={salvarPerfil.isPending}>
            {salvarPerfil.isPending ? 'Salvando…' : 'Salvar dados'}
          </button>
        </form>
      </section>

      <section className="card">
        <h2>Senha</h2>
        <form onSubmit={aoTrocarSenha} noValidate>
          <div className="campo">
            <label htmlFor="senha-atual">Senha de confirmação</label>
            <input
              id="senha-atual"
              type="password"
              autoComplete="current-password"
              value={senhaAtual}
              onChange={(e) => setSenhaAtual(e.target.value)}
              required
            />
          </div>

          <div className="campo">
            <label htmlFor="nova-senha">Nova senha</label>
            <input
              id="nova-senha"
              type="password"
              autoComplete="new-password"
              value={novaSenha}
              onChange={(e) => setNovaSenha(e.target.value)}
              required
            />
          </div>

          <div className="campo">
            <label htmlFor="confirmar-senha">Confirmar nova senha</label>
            <input
              id="confirmar-senha"
              type="password"
              autoComplete="new-password"
              value={confirmacao}
              onChange={(e) => setConfirmacao(e.target.value)}
              required
            />
          </div>

          <button type="submit" className="botao" disabled={trocarSenha.isPending}>
            {trocarSenha.isPending ? 'Alterando…' : 'Alterar senha'}
          </button>
        </form>
      </section>

      <section className="card">
        <h2>Segurança (MFA)</h2>

        {usuario.mfa_obrigatorio ? (
          <>
            <p>Ativo (exigido pelo seu perfil)</p>
            {usuario.mfa_pendente && (
              <FluxoAtivarMfa
                setup={setupMfa}
                confirmar={confirmarMfa}
                codigo={codigoAtivar}
                setCodigo={setCodigoAtivar}
              />
            )}
          </>
        ) : usuario.mfa_confirmado ? (
          <>
            <p>Ativo</p>
            {!mostrarCodigo ? (
              <button
                type="button"
                className="botao botao--secundario"
                onClick={() => setMostrarCodigo(true)}
              >
                Desabilitar MFA
              </button>
            ) : (
              <div className="campo">
                <label htmlFor="codigo-desabilitar">Código do autenticador</label>
                <input
                  id="codigo-desabilitar"
                  inputMode="numeric"
                  maxLength={6}
                  value={codigoDesabilitar}
                  onChange={(e) => setCodigoDesabilitar(e.target.value.replace(/\D/g, ''))}
                />
                <button
                  type="button"
                  className="botao"
                  onClick={() => desabilitarMfa.mutate()}
                  disabled={desabilitarMfa.isPending}
                >
                  {desabilitarMfa.isPending ? 'Desabilitando…' : 'Confirmar desabilita'}
                </button>
              </div>
            )}
          </>
        ) : (
          <>
            <p>Desativado</p>
            <FluxoAtivarMfa
              setup={setupMfa}
              confirmar={confirmarMfa}
              codigo={codigoAtivar}
              setCodigo={setCodigoAtivar}
            />
          </>
        )}
      </section>
    </div>
  )
}

/** Bloco de ativação do MFA: gera o segredo (setup) e confirma com um código. */
function FluxoAtivarMfa({
  setup,
  confirmar,
  codigo,
  setCodigo,
}: {
  setup: { data?: { secret: string; qr_url: string }; mutate: () => void; isPending: boolean }
  confirmar: { mutate: () => void; isPending: boolean }
  codigo: string
  setCodigo: (valor: string) => void
}) {
  return (
    <div>
      {setup.data ? (
        <div className="alerta alerta--info">
          <p>Adicione a conta ao seu autenticador usando o segredo abaixo:</p>
          <p style={{ fontFamily: 'var(--fonte-mono)', wordBreak: 'break-all' }}>{setup.data.secret}</p>
        </div>
      ) : (
        <button type="button" className="botao botao--secundario" onClick={() => setup.mutate()} disabled={setup.isPending}>
          {setup.isPending ? 'Gerando…' : 'Gerar segredo / QR Code'}
        </button>
      )}

      {setup.data && (
        <div className="campo">
          <label htmlFor="codigo-ativar">Código de 6 dígitos</label>
          <input
            id="codigo-ativar"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            value={codigo}
            onChange={(e) => setCodigo(e.target.value.replace(/\D/g, ''))}
          />
          <button type="button" className="botao" onClick={() => confirmar.mutate()} disabled={confirmar.isPending}>
            {confirmar.isPending ? 'Validando…' : 'Confirmar MFA'}
          </button>
        </div>
      )}
    </div>
  )
}