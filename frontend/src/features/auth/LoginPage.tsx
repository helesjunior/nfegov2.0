import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { authApi, mensagemDeErro } from '../../lib/api'

/**
 * Tela de login.
 *
 * Em sucesso, decide o próximo passo: se o usuário precisa configurar o
 * segundo fator (`mfa_pendente`), vai para `/mfa`; caso contrário, para a
 * tela inicial.
 */
export function LoginPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [email, setEmail] = useState('')
  const [senha, setSenha] = useState('')
  const [erro, setErro] = useState<string | null>(null)

  const login = useMutation({
    mutationFn: () => authApi.login(email, senha),
    onSuccess: async ({ user }) => {
      // Semeia a sessão e decide o destino.
      queryClient.setQueryData(['sessao'], { user })
      navigate(user.mfa_pendente ? '/mfa' : '/')
    },
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  function aoEnviar(evento: FormEvent) {
    evento.preventDefault()
    setErro(null)
    login.mutate()
  }

  return (
    <div className="auth-tela">
      <div className="card auth-card">
        <h1 className="auth-titulo">NF-e Gov</h1>
        <p className="auth-subtitulo">Acesso ao sistema de recepção e consulta de NF-e.</p>

        {erro && (
          <div className="alerta alerta--erro" role="alert">
            {erro}
          </div>
        )}

        <form onSubmit={aoEnviar} noValidate>
          <div className="campo">
            <label htmlFor="email">E-mail</label>
            <input
              id="email"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </div>

          <div className="campo">
            <label htmlFor="senha">Senha</label>
            <input
              id="senha"
              type="password"
              autoComplete="current-password"
              value={senha}
              onChange={(e) => setSenha(e.target.value)}
              required
            />
          </div>

          <button type="submit" className="botao" disabled={login.isPending} style={{ width: '100%' }}>
            {login.isPending ? 'Entrando…' : 'Entrar'}
          </button>
        </form>
      </div>
    </div>
  )
}