import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, it, expect } from 'vitest'
import { Sidebar } from './Sidebar'

function renderizar(props: { permissoes: string[]; podeTodas?: boolean; versao?: string | null }) {
  return render(
    <MemoryRouter>
      <Sidebar {...props} />
    </MemoryRouter>,
  )
}

describe('Sidebar', () => {
  it('exibe a versão + timestamp da build no rodapé', () => {
    renderizar({ permissoes: [], versao: '2.0.0-20261106.175300' })

    expect(screen.getByTestId('versao-app')).toHaveTextContent(
      'NFeGov2.0 - v. 2.0.0-20261106.175300',
    )
  })

  it('sem versão disponível, não renderiza o rodapé', () => {
    renderizar({ permissoes: [], versao: null })

    expect(screen.queryByTestId('versao-app')).not.toBeInTheDocument()
  })

  it('mostra apenas os itens permitidos ao usuário', () => {
    renderizar({ permissoes: ['nfe.view'], versao: null })

    expect(screen.getByRole('link', { name: 'NF-e' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'NFS-e' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Usuários' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Entidades' })).not.toBeInTheDocument()
  })
})