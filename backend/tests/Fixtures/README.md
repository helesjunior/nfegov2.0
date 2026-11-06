# Fixtures de teste

Arquivos `*.pfx` **não são versionados** (o `.gitignore` ignora `*.pfx`/`*.p12`)
— são certificados de teste gerados localmente. Num clone limpo, gere-os antes
de rodar a suíte:

```bash
# Certificado "moderno" (AES) — usado pelos testes de upload/rotação.
docker compose exec -T app php tools/make_test_pfx.php 11222333000181 senha-pfx tests/Fixtures/cert_e_cnpj.pfx
docker compose exec -T app php tools/make_test_pfx.php 99888777000166 senha-pfx tests/Fixtures/cert_outro_cnpj.pfx

# Certificado "legado" (RC2-40, como os A1 reais da ICP-Brasil).
# Exige o provider `legacy` do OpenSSL 3 ativo (já configurado na imagem).
docker compose exec -T app sh tools/make_legacy_test_pfx.sh 11222333000181 senha-pfx tests/Fixtures/cert_e_cnpj_legacy.pfx
```

## Por que existe um fixture "legado"?

Certificados A1 reais da ICP-Brasil costumam usar `pbeWithSHA1And40BitRC2-CBC`
no PKCS#12. No OpenSSL 3 esse algoritmo vive no provider `legacy`, desativado
por padrão. Sem ele, `openssl_pkcs12_read` falha com:

```
error:0308010C:digital envelope routines::unsupported
```

O `cert_e_cnpj_legacy.pfx` reproduz exatamente esse cenário e garante
(regressão) que o ambiente mantém o provider `legacy` ativo — ver
`tests/Feature/Certificate/CertificadoLegadoTest.php`.

## `nfe_autorizada.xml`

Um `nfeProc` COMPLETO e real (autorizado) usado para exercitar a geração de
DANFE (PDF) de ponta a ponta pelo endpoint `/api/nfes/{chave}/danfe`. É versionado
porque não contém segredos (é um documento fiscal público) e permite testar a
renderização real da `sped-da` sem tocar a SEFAZ.