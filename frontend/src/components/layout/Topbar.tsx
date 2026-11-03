import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { authApi, type Usuario } from '../../lib/api'

/**
 * Barra superior: identifica o usuário e oferece o logout.
 */
export function Topbar({ usuario }: { usuario: Usuario }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const sair = useMutation({
    mutationFn: () => authApi.logout(),
    onSettled: () => {
      // Limpa o cache (sessão e dados do tenant) e volta ao login.
      queryClient.clear()
      navigate('/login')
    },
  })

  const papeis = usuario.papeis.join(', ')

  return (
    <header className="shell__topbar">
      <div>
        <strong>{usuario.name}</strong>
        {papeis && <span className="shell__usuario"> · {papeis}</span>}
      </div>
      <button
        type="button"
        className="botao botao--secundario"
        onClick={() => sair.mutate()}
        disabled={sair.isPending}
      >
        {sair.isPending ? 'Saindo…' : 'Sair'}
      </button>
    </header>
  )
}