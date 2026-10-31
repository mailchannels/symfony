<?php

// php webhook.php public-key: prints the PEM public key of the synthetic signing key
// php webhook.php post URL EVENT [tampered]: posts a webhook signed like MailChannels does, prints the status code
//
// The key pair comes from a fixed seed: it is synthetic and signs nothing but this test.

require \dirname(__DIR__).'/autoload.php';

$keyPair = sodium_crypto_sign_seed_keypair(str_repeat("\x5e", \SODIUM_CRYPTO_SIGN_SEEDBYTES));

if ('public-key' === ($argv[1] ?? null)) {
    echo "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode("\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00".sodium_crypto_sign_publickey($keyPair)), 64, "\n")."-----END PUBLIC KEY-----\n";

    exit(0);
}

[, , $url, $event] = $argv;
$tampered = 'tampered' === ($argv[4] ?? null);

$body = json_encode([[
    'email' => 'sender@example.com',
    'customer_handle' => 'synthetic-account',
    'timestamp' => time(),
    'event' => $event,
    'request_id' => 'e2e-request',
    'recipients' => ['recipient@example.com'],
]]);

$digest = 'sha-256=:'.base64_encode(hash('sha256', $body, true)).':';
$signatureParams = '("content-digest");created='.time().';alg="ed25519";keyid="mckey"';
$signature = sodium_crypto_sign_detached("\"content-digest\": $digest\n\"@signature-params\": $signatureParams", sodium_crypto_sign_secretkey($keyPair));

if ($tampered) {
    // The signature and digest stay those of the original body
    $body = str_replace('recipient@example.com', 'attacker@example.com', $body);
}

$response = Symfony\Component\HttpClient\HttpClient::create()->request('POST', $url, [
    'headers' => [
        'Content-Type' => 'application/json',
        'Content-Digest' => $digest,
        'Signature-Input' => 'sig_1='.$signatureParams,
        'Signature' => 'sig_1=:'.base64_encode($signature).':',
    ],
    'body' => $body,
]);

echo $response->getStatusCode()."\n";
