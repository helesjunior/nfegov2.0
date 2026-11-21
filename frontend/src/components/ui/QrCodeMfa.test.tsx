import { render, screen } from '@testing-library/react'
import { describe, it, expect } from 'vitest'
import { QrCodeMfa } from './QrCodeMfa'

const URL_OTP = 'otpauth://totp/NFeGov:fulano@x.com?secret=JBSWY3DPEHPK3PXP&issuer=NFeGov'

describe('QrCodeMfa', () => {
  it('desenha o QR Code localmente (SVG com módulos) e mostra o segredo em texto', () => {
    const { container } = render(<QrCodeMfa qrUrl={URL_OTP} segredo="JBSWY3DPEHPK3PXP" />)

    const svg = container.querySelector('svg')
    expect(svg).not.toBeNull()
    expect(screen.getByTitle(/qr code para cadastrar o autenticador/i)).toBeInTheDocument()
    // O QR tem módulos desenhados (não é um quadro vazio).
    expect(container.querySelectorAll('path').length).toBeGreaterThan(0)
    expect(screen.getByText('JBSWY3DPEHPK3PXP')).toBeInTheDocument()
  })

  it('não faz nenhuma requisição a serviço externo (nada de <img src> remoto)', () => {
    const { container } = render(<QrCodeMfa qrUrl={URL_OTP} segredo="JBSWY3DPEHPK3PXP" />)

    expect(container.querySelector('img')).toBeNull()
    expect(container.innerHTML).not.toMatch(/https?:\/\//)
  })

  it('QR diferente para URL diferente', () => {
    const a = render(<QrCodeMfa qrUrl={URL_OTP} segredo="A" />).container.innerHTML
    const b = render(<QrCodeMfa qrUrl={URL_OTP + '1'} segredo="A" />).container.innerHTML
    expect(a).not.toEqual(b)
  })
})
