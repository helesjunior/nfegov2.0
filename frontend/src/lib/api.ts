import axios, { AxiosError } from 'axios'

/**
 * Cliente HTTP da SPA.
 *
 * A aplicação roda no MESMO domínio do Laravel e usa autenticação por cookie de
 * sessão (Sanctum stateful). Por isso:
 * - `withCredentials` envia/recebe o cookie de sessão;
 * - `withXSRFToken` faz o axios ler o cookie `XSRF-TOKEN` e enviar o cabeçalho
 *   `X-XSRF-TOKEN`, que o Laravel valida (proteção CSRF).
 */
export const http = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

/**
 * Extrai uma mensagem de erro legível de uma falha da API.
 *
 * O Laravel devolve `{ message, errors }` para erros de validação; usamos a
 * primeira mensagem de campo quando existir.
 */
export function mensagemDeErro(erro: unknown): string {
  if (erro instanceof AxiosError) {
    const data = erro.response?.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined

    if (data?.errors) {
      const primeira = Object.values(data.errors)[0]
      if (primeira?.length) return primeira[0]
    }

    if (data?.message) return data.message
    if (erro.message) return erro.message
  }

  return 'Não foi possível concluir a operação.'
}

// ---- Tipos da API -----------------------------------------------------------

export interface Usuario {
  id: number
  name: string
  email: string
  tenant_id: number | null
  papeis: string[]
  permissoes: string[]
  mfa_pendente: boolean
  mfa_confirmado: boolean
}

export interface RespostaLogin {
  user: Usuario
}

// ---- Endpoints --------------------------------------------------------------

export const authApi = {
  async login(email: string, password: string, remember = false): Promise<RespostaLogin> {
    const { data } = await http.post<RespostaLogin>('/login', { email, password, remember })
    return data
  },

  async me(): Promise<RespostaLogin> {
    const { data } = await http.get<RespostaLogin>('/me')
    return data
  },

  async logout(): Promise<void> {
    await http.post('/logout')
  },
}

export interface SetupMfa {
  secret: string
  qr_url: string
}

export const mfaApi = {
  async setup(): Promise<SetupMfa> {
    const { data } = await http.post<SetupMfa>('/mfa/setup')
    return data
  },

  async confirm(code: string): Promise<void> {
    await http.post('/mfa/confirm', { code })
  },

  async verify(code: string): Promise<{ recente_ate: string }> {
    const { data } = await http.post('/mfa/verify', { code })
    return data
  },
}