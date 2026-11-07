import { fireEvent, render, screen } from '@testing-library/react'
import { describe, it, expect } from 'vitest'
import { GraficoFornecedores, GraficoPorDia } from './Graficos'

function dias(quantidades: number[]) {
  return quantidades.map((q, i) => ({
    data: `2026-10-${String(i + 1).padStart(2, '0')}`,
    quantidade: q,
    valor: q * 100,
  }))
}

describe('GraficoPorDia', () => {
  it('mostra o total do período e uma coluna por dia', () => {
    const { container } = render(<GraficoPorDia dias={dias([1, 0, 3])} />)

    expect(screen.getByTestId('destaque-dia')).toHaveTextContent('4 NF-e')
    expect(container.querySelectorAll('.colunas__slot')).toHaveLength(3)
  })

  it('ao passar o mouse, destaca o dia', () => {
    const { container } = render(<GraficoPorDia dias={dias([1, 0, 3])} />)

    fireEvent.mouseEnter(container.querySelectorAll('.colunas__slot')[2])

    expect(screen.getByTestId('destaque-dia')).toHaveTextContent('3 NF-e')
    expect(screen.getByTestId('destaque-dia')).toHaveTextContent('03/10')
  })

  it('sem notas, exibe mensagem em vez do gráfico', () => {
    render(<GraficoPorDia dias={dias([0, 0])} />)

    expect(screen.getByText('Nenhuma NF-e emitida nos últimos 30 dias.')).toBeInTheDocument()
  })
})

describe('GraficoPorDia (NFS-e)', () => {
  it('usa os textos de NFS-e', () => {
    render(<GraficoPorDia dias={dias([2, 1])} documento="NFS-e" />)

    expect(screen.getByRole('heading', { name: 'NFS-e por dia' })).toBeInTheDocument()
    expect(screen.getByTestId('destaque-dia')).toHaveTextContent('3 NFS-e')
  })
})

describe('GraficoFornecedores (NFS-e)', () => {
  it('chama os emitentes de prestadores', () => {
    render(
      <GraficoFornecedores
        documento="NFS-e"
        fornecedores={[{ cnpj: '1', nome: 'Serralheria Exemplo', quantidade: 3, valor: 5000 }]}
      />,
    )

    expect(screen.getByRole('heading', { name: 'Maiores prestadores' })).toBeInTheDocument()
    expect(screen.getByRole('listitem')).toHaveAttribute('title', 'Serralheria Exemplo · 3 NFS-e')
  })

  it('sem prestadores, exibe a mensagem de prestadores', () => {
    render(<GraficoFornecedores documento="NFS-e" fornecedores={[]} />)

    expect(screen.getByText('Sem notas de prestadores no período.')).toBeInTheDocument()
  })
})

describe('GraficoFornecedores', () => {
  it('lista os fornecedores com o valor na ponta da barra', () => {
    render(
      <GraficoFornecedores
        fornecedores={[
          { cnpj: '1', nome: 'Alfa Ltda', quantidade: 2, valor: 1000 },
          { cnpj: '2', nome: 'Beta SA', quantidade: 1, valor: 250 },
        ]}
      />,
    )

    const itens = screen.getAllByRole('listitem')
    expect(itens).toHaveLength(2)
    expect(itens[0]).toHaveTextContent('Alfa Ltda')
    expect(itens[0]).toHaveTextContent('R$')
  })

  it('sem fornecedores, exibe mensagem', () => {
    render(<GraficoFornecedores fornecedores={[]} />)

    expect(screen.getByText('Sem notas de fornecedores no período.')).toBeInTheDocument()
  })
})
