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

// ---- Respostas paginadas ----------------------------------------------------

/** Envelope padrão paginado do Laravel (`data` + `meta`). */
export interface Paginado<T> {
  data: T[]
  links: Record<string, string | null>
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

// ---- Unidades ---------------------------------------------------------------

export interface CertificadoResumo {
  cnpj: string
  titular: string
  valido_ate: string
  dias_restantes: number
  vencido: boolean
}

export interface Unidade {
  id: number
  codigo_unidade: string | null
  nome: string
  nome_resumido: string | null
  cnpj: string
  ie: string | null
  uf: string
  tp_amb: number | null
  ativo: boolean
  certificado: CertificadoResumo | null
  created_at: string | null
}

export interface NovaUnidade {
  nome: string
  nome_resumido?: string
  codigo_unidade?: string
  cnpj: string
  ie?: string
  im?: string
  uf: string
  tp_amb?: 1 | 2
  ativo?: boolean
}

export const unidadesApi = {
  async listar(perPage = 50, page = 1): Promise<Paginado<Unidade>> {
    const { data } = await http.get<Paginado<Unidade>>('/unidades', {
      params: { per_page: perPage, page },
    })
    return data
  },

  async criar(payload: NovaUnidade): Promise<Unidade> {
    const { data } = await http.post<{ data: Unidade }>('/unidades', payload)
    return data.data
  },
}

// ---- Certificado ------------------------------------------------------------

export interface Certificado {
  id: number
  unidade_id: number
  cnpj: string
  titular: string
  emissor: string
  valido_de: string
  valido_ate: string
  dias_restantes: number
  vencido: boolean
  ativo: boolean
}

/**
 * Erro de step-up: o backend exige uma verificação MFA recente.
 *
 * Identificado pela flag específica do corpo 403, para distinguir de uma
 * simples falta de permissão (mesmo status, corpo sem essa chave).
 */
export function ehErroDeStepUp(erro: unknown): boolean {
  const data = (erro as { response?: { data?: { mfa_step_up_required?: boolean } } })?.response?.data
  return data?.mfa_step_up_required === true
}

export const certificadoApi = {
  async obter(unidadeId: number): Promise<Certificado | null> {
    const { data } = await http.get<{ certificado: Certificado | null }>(
      `/unidades/${unidadeId}/certificado`,
    )
    return data.certificado
  },

  async enviar(unidadeId: number, arquivo: File, senha: string): Promise<Certificado> {
    const form = new FormData()
    form.append('arquivo', arquivo)
    form.append('senha', senha)

    const { data } = await http.post<{ certificado: Certificado }>(
      `/unidades/${unidadeId}/certificado`,
      form,
    )
    return data.certificado
  },
}

// ---- Sincronizações ---------------------------------------------------------

export interface SyncRun {
  id: number
  unidade_id: number
  iniciado_em: string | null
  finalizado_em: string | null
  nsu_inicial: number
  nsu_final: number
  docs_count: number
  status: 'em_andamento' | 'sucesso' | 'erro' | 'bloqueado'
  erro: string | null
}

export const syncApi = {
  async listar(
    filtros: { unidade_id?: number; status?: string; per_page?: number; page?: number } = {},
  ): Promise<Paginado<SyncRun>> {
    const { data } = await http.get<Paginado<SyncRun>>('/sync-runs', { params: filtros })
    return data
  },
}

// ---- Dashboard --------------------------------------------------------------

export interface DashboardResumo {
  nfes: {
    total: number
    ultimos_30_dias: number
    valor_ultimos_30_dias: number
    resumos: number
  }
  unidades: { total: number; ativas: number }
  certificados: { vencendo_30_dias: number; vencidos: number; expirados: number }
  sync: {
    ultimas: Array<{
      id: number
      unidade_id: number
      status: string
      docs_count: number
      iniciado_em: string | null
    }>
    com_erro_24h: number
    circuito_aberto: boolean
  }
}

export const dashboardApi = {
  async obter(): Promise<DashboardResumo> {
    const { data } = await http.get<DashboardResumo>('/dashboard')
    return data
  },
}

// ---- NF-e -------------------------------------------------------------------

export interface Nfe {
  id: number
  chave: string
  numero: string | null
  serie: string | null
  modelo: string
  unidade_id: number
  emitente_cnpj: string | null
  emitente_nome: string | null
  dest_cnpj: string | null
  valor_total: string | null
  natureza_operacao: string | null
  data_emissao: string | null
  cstat: string | null
  estado: string
  emitente_proprio: boolean
  tem_xml: boolean
  xml_url: string | null
  itens?: NfeItem[]
  created_at: string | null
}

export interface NfeItem {
  id: number
  sequencial: number
  descricao: string
  cfop: string | null
  unidade_medida: string | null
  quantidade: string | null
  valor_unitario: string | null
  valor_total: string | null
}

export interface FiltrosNfe {
  de?: string
  ate?: string
  unidade_id?: number
  emitente_cnpj?: string
  valor_min?: number
  valor_max?: number
  chave?: string
  q?: string
  per_page?: number
  page?: number
}

export const nfeApi = {
  async listar(filtros: FiltrosNfe = {}): Promise<Paginado<Nfe>> {
    const { data } = await http.get<Paginado<Nfe>>('/nfes', { params: filtros })
    return data
  },

  async obter(chave: string): Promise<Nfe> {
    const { data } = await http.get<{ data: Nfe }>(`/nfes/${chave}`)
    return data.data
  },

  async exportar(filtros: FiltrosNfe, formato: 'csv' | 'xlsx'): Promise<string> {
    const { data } = await http.post<{ message: string }>('/nfes/export', { ...filtros, formato })
    return data.message
  },

  async exportarXml(filtros: FiltrosNfe): Promise<string> {
    const { data } = await http.post<{ message: string }>('/nfes/export-xml', filtros)
    return data.message
  },
}

// ---- Notificações -----------------------------------------------------------

export interface Notificacao {
  id: string
  lida: boolean
  criada_em: string | null
  dados: { caminho?: string; formato?: string; total?: number }
  url_download: string | null
}

export const notificacoesApi = {
  async listar(): Promise<{ data: Notificacao[]; nao_lidas: number }> {
    const { data } = await http.get<{ data: Notificacao[]; nao_lidas: number }>('/notifications')
    return data
  },

  async marcarComoLida(id: string): Promise<void> {
    await http.post(`/notifications/${id}/lida`)
  },
}