#!/bin/sh
# Gera um PFX de teste com criptografia LEGADA (RC2/3DES), como os
# certificados A1 reais da ICP-Brasil.
#
# No OpenSSL 3, PKCS#12 legado exige o provider `legacy` tanto para exportar
# quanto para ler. Este script força algoritmos legados para produzir um
# fixture que reproduz o cenário real (e que falha sem o provider legacy).
#
# Uso: sh tools/make_legacy_test_pfx.sh <cnpj14> <senha> <arquivo-saida.pfx>
set -e

CNPJ="${1:-11222333000181}"
SENHA="${2:-senha-pfx}"
SAIDA="${3:-tests/Fixtures/cert_e_cnpj_legacy.pfx}"
CNPJ=$(echo "$CNPJ" | tr -cd '0-9')

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

cat > "$TMP/openssl.cnf" <<CNF
[ req ]
distinguished_name = dn
prompt = no
req_extensions = v3_req

[ dn ]
C = BR
O = Empresa Teste NFeGov
CN = Empresa Teste NFeGov

[ v3_req ]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
2.16.76.1.3.3 = ASN1:UTF8String:${CNPJ}
CNF

openssl req -x509 -newkey rsa:2048 \
    -keyout "$TMP/key.pem" -out "$TMP/cert.pem" \
    -days 3650 -nodes -config "$TMP/openssl.cnf" \
    -extensions v3_req 2>/dev/null

# Reproduz EXATAMENTE o cenário dos certificados A1 reais da ICP-Brasil:
# - `-certpbe PBE-SHA1-RC2-40`: cifra o CONTEÚDO (o certificado) com RC2-40,
#   que no OpenSSL 3 só existe no provider `legacy`. É este, especificamente,
#   o algoritmo que faz `openssl_pkcs12_read` falhar com
#   "digital envelope routines::unsupported" quando o provider legacy
#   não está ativo.
# - `-keypbe PBE-SHA1-3DES` e `-macalg sha1`: a chave cifrada e o MAC
#   (também legados, mas ainda disponíveis no default por compatibilidade).
openssl pkcs12 -export \
    -out "$SAIDA" \
    -inkey "$TMP/key.pem" -in "$TMP/cert.pem" \
    -passout "pass:$SENHA" \
    -certpbe PBE-SHA1-RC2-40 \
    -keypbe PBE-SHA1-3DES \
    -macalg sha1

echo "PFX legado gerado: $SAIDA (CNPJ $CNPJ, senha $SENHA)"