import { QRCodeSVG } from 'qrcode.react'

interface Props {
  /** URL `otpauth://` devolvida por `POST /api/mfa/setup` (já inclui o segredo). */
  qrUrl: string
  /** Mesmo segredo em texto, para quem não consegue escanear. */
  segredo: string
}

/**
 * Cadastro do autenticador (TOTP): QR Code + segredo em texto.
 *
 * O QR é desenhado AQUI, no navegador. O segredo nunca é enviado a um serviço
 * externo de "gerador de QR Code".
 */
export function QrCodeMfa({ qrUrl, segredo }: Props) {
  return (
    <div className="mfa-qr">
      <p>
        Escaneie o QR Code com o seu aplicativo autenticador (Google Authenticator, Microsoft
        Authenticator, Authy etc.):
      </p>
      {/* Fundo branco fixo: o QR precisa de contraste também no tema escuro. */}
      <div className="mfa-qr__imagem">
        <QRCodeSVG value={qrUrl} size={192} level="M" marginSize={4} title="QR Code para cadastrar o autenticador" />
      </div>
      <p>Não consegue escanear? Digite este código no aplicativo:</p>
      <p className="mfa-qr__segredo">{segredo}</p>
    </div>
  )
}
