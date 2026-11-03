<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Certificado e-CNPJ próximo do vencimento</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; line-height: 1.5;">
    <h2 style="color: #b45309;">Certificado digital próximo do vencimento</h2>

    <p>
        O certificado e-CNPJ da unidade <strong>{{ $unidade->nome }}</strong>
        vence em <strong>{{ $diasRestantes }} dia(s)</strong>.
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; margin: 16px 0;">
        <tr>
            <td style="border: 1px solid #e5e7eb;"><strong>Unidade</strong></td>
            <td style="border: 1px solid #e5e7eb;">{{ $unidade->nome }} (CNPJ {{ $unidade->cnpj }})</td>
        </tr>
        <tr>
            <td style="border: 1px solid #e5e7eb;"><strong>Titular</strong></td>
            <td style="border: 1px solid #e5e7eb;">{{ $certificate->titular }}</td>
        </tr>
        <tr>
            <td style="border: 1px solid #e5e7eb;"><strong>Válido até</strong></td>
            <td style="border: 1px solid #e5e7eb;">
                {{ optional($certificate->valido_ate)->format('d/m/Y H:i') }}
            </td>
        </tr>
    </table>

    <p>
        Providencie a renovação do certificado e faça o upload do novo arquivo
        (.pfx/.p12) no painel, em <em>Unidades → Certificado</em>. A operação
        exige uma nova verificação de segurança (MFA).
    </p>

    <p style="color: #6b7280; font-size: 12px;">
        Este é um aviso automático. Após o vencimento, a consulta à SEFAZ para
        esta unidade ficará indisponível até a substituição do certificado.
    </p>
</body>
</html>