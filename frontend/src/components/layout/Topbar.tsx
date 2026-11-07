import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { authApi, type Usuario } from '../../lib/api'
import { useTema } from '../../lib/tema'
import { SeletorEntidade } from '../../features/sessao/SeletorEntidade'
import { Icone } from '../ui/Icone'

/** Iniciais para o avatar (ex.: "Maria da Silva" → "MS"). */
function iniciais(nome: string): string {
  const partes = nome.trim().split(/\s+/).filter(Boolean)
  if (partes.length === 0) return '?'
  const primeira = partes[0][0]
  const ultima = partes.length > 1 ? partes[partes.length - 1][0] : ''
  return (primeira + ultima).toUpperCase()
}

/**
 * Barra superior: entidade ativa, alternância de tema, usuário e logout.
 */
export function Topbar({ usuario }: { usuario: Usuario }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { tema, alternar } = useTema()

  const sair = useMutation({
    mutationFn: () => authApi.logout(),
    onSettled: () => {
      // Limpa o cache (sessão e dados do tenant) e volta ao login.
      queryClient.clear()
      navigate('/login')
    },
  })

  const papeis = usuario.papeis.join(', ')
  const escuro = tema === 'escuro'

  return (
    <header className="shell__topbar">
      <SeletorEntidade />
      <div className="shell__topbar-acoes">
        <button
          type="button"
          className="botao-icone"
          onClick={alternar}
          aria-label={escuro ? 'Ativar tema claro' : 'Ativar tema escuro'}
          title={escuro ? 'Tema claro' : 'Tema escuro'}
        >
          <Icone nome={escuro ? 'sol' : 'lua'} />
        </button>

        <Link to="/minha-conta" aria-label="Minha conta" className="shell__perfil">
          <span className="avatar" aria-hidden="true">
            {iniciais(usuario.name)}
          </span>
          <span className="shell__perfil-texto">
            <strong>{usuario.name}</strong>
            {papeis && <span className="shell__usuario">{papeis}</span>}
          </span>
        </Link>

        <button
          type="button"
          className="botao botao--secundario botao--compacto"
          onClick={() => sair.mutate()}
          disabled={sair.isPending}
        >
          <Icone nome="sair" tamanho={16} />
          {sair.isPending ? 'Saindo…' : 'Sair'}
        </button>
      </div>
    </header>
  )
}
