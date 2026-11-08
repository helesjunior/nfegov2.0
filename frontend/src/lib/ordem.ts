import type { DirecaoOrdem } from './api'

export interface Ordem<C extends string> {
  campo: C
  direcao: DirecaoOrdem
}

/**
 * Próxima ordem ao clicar numa coluna: a mesma coluna inverte o sentido;
 * outra coluna começa pelo sentido inicial dela (datas e valores do maior
 * para o menor, nomes de A a Z).
 */
export function proximaOrdem<C extends string>(
  atual: Ordem<C>,
  campo: C,
  inicial: DirecaoOrdem,
): Ordem<C> {
  if (atual.campo === campo) {
    return { campo, direcao: atual.direcao === 'asc' ? 'desc' : 'asc' }
  }
  return { campo, direcao: inicial }
}
