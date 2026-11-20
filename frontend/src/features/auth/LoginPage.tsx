import { useState, type FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { authApi, mensagemDeErro } from '../../lib/api'
import { AuthLayout } from '../../components/layout/AuthLayout'

/**
 * Mensagens para os códigos que o servidor devolve em `/login?erro=...` após o
 * login com Google. Só códigos conhecidos são exibidos (nada da URL é refletido).
 * O motivo detalhado de uma negativa fica só na auditoria do servidor.
 */
const ERROS_GOOGLE: Record<string, string> = {
  google_nao_autorizado: 'Esta conta Google não tem acesso ao sistema. Procure o administrador.',
  google_cancelado: 'O login com o Google foi cancelado.',
  google_falhou: 'Não foi possível entrar com o Google. Tente novamente.',
}

/**
 * Tela de login.
 *
 * Em sucesso, decide o próximo passo: se o usuário precisa configurar o
 * segundo fator (`mfa_pendente`), vai para `/mfa`; caso contrário, para a
 * tela inicial.
 *
 * Quando o servidor habilita o login com Google Workspace (`/login/opcoes`),
 * mostra o botão "Entrar com Google" — é uma navegação completa para o servidor
 * (fluxo OAuth), que devolve o usuário logado à tela inicial; o segundo fator
 * continua sendo cobrado pelo guard de rota, como no login por senha.
 */
export function LoginPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [params] = useSearchParams()

  const [email, setEmail] = useState('')
  const [senha, setSenha] = useState('')
  const [erro, setErro] = useState<string | null>(() => ERROS_GOOGLE[params.get('erro') ?? ''] ?? null)

  const opcoes = useQuery({
    queryKey: ['login-opcoes'],
    queryFn: () => authApi.opcoes(),
    staleTime: 5 * 60 * 1000,
    retry: false,
  })

  const login = useMutation({
    mutationFn: () => authApi.login(email, senha),
    onSuccess: async ({ user }) => {
      // Semeia a sessão e decide o destino.
      queryClient.setQueryData(['sessao'], { user })
      navigate(user.mfa_pendente || user.mfa_verificacao_pendente ? '/mfa' : '/')
    },
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  function aoEnviar(evento: FormEvent) {
    evento.preventDefault()
    setErro(null)
    login.mutate()
  }

  return (
    <AuthLayout>
      <h1 className="auth-titulo">Acesse sua conta</h1>
      <p className="auth-subtitulo">Entre com seu e-mail e senha para acessar o sistema.</p>

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

        <button type="submit" className="botao botao--bloco" disabled={login.isPending}>
          {login.isPending ? 'Entrando…' : 'Entrar'}
        </button>
      </form>

      {opcoes.data?.google && (
        <>
          <div className="auth-divisor" aria-hidden="true">
            ou
          </div>
          <a href="/auth/google/redirect" className="botao botao--secundario botao--bloco">
            Entrar com Google
          </a>
        </>
      )}
    </AuthLayout>
  )
}
