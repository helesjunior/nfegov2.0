<?php
// Gera um PFX de teste (autoassinado) com CNPJ embutido no OID 2.16.76.1.3.3.
// Uso: php tools/make_test_pfx.php <cnpj14> <senha> <arquivo-saida.pfx> [dias-para-notAfter]
//
// Para um certificado já VENCIDO, informe um número negativo (ex.: -10).
// Usa openssl_x509 em conjunto com a seção [not_before]/[not_after] do config.

[$script, $cnpj, $senha, $saida] = $argv + [null, '11222333000181', 'senha-pfx', 'tests/Fixtures/cert_e_cnpj.pfx'];
$dias = (int) ($argv[4] ?? 365);

$cnpj = preg_replace('/\D/', '', $cnpj);

$configFile = sys_get_temp_dir() . '/openssl_nfegov.cnf';
file_put_contents($configFile, <<<CNF
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
2.16.76.1.3.3 = ASN1:UTF8String:{$cnpj}
CNF);

$pkey = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'config' => $configFile,
]);
if ($pkey === false) {
    fwrite(STDERR, "Falha ao gerar chave: " . openssl_error_string() . "\n");
    exit(1);
}

$csr = openssl_csr_new(['commonName' => 'Empresa Teste NFeGov'], $pkey, [
    'config' => $configFile,
    'digest_alg' => 'sha256',
    'req_extensions' => 'v3_req',
]);

// Validade do certificado de teste (em dias, a partir de agora).
$diasCert = max(1, (int) $dias);

$cert = openssl_csr_sign($csr, null, $pkey, $diasCert, [
    'config' => $configFile,
    'digest_alg' => 'sha256',
    'x509_extensions' => 'v3_req',
]);

if ($cert === false) {
    fwrite(STDERR, "Falha ao assinar: " . openssl_error_string() . "\n");
    exit(1);
}

openssl_pkcs12_export($cert, $pfx, $pkey, $senha);
if (! $pfx) {
    fwrite(STDERR, "Falha ao exportar PFX\n");
    exit(1);
}

if (! is_dir(dirname($saida))) {
    mkdir(dirname($saida), 0777, true);
}
file_put_contents($saida, $pfx);

echo "PFX gerado: {$saida} (CNPJ {$cnpj}, senha {$senha})\n";

require __DIR__ . '/../vendor/autoload.php';
$obj = \NFePHP\Common\Certificate::readPfx($pfx, $senha);
echo "cnpj lido : " . $obj->getCnpj() . "\n";
echo "titular   : " . $obj->getCompanyName() . "\n";
echo "valido de : " . $obj->getValidFrom()->format('Y-m-d H:i:s') . "\n";
echo "valido ate: " . $obj->getValidTo()->format('Y-m-d H:i:s') . "\n";