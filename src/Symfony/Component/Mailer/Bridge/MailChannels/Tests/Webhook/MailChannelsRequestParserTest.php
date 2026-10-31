<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\MailChannels\Tests\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Bridge\MailChannels\RemoteEvent\MailChannelsPayloadConverter;
use Symfony\Component\Mailer\Bridge\MailChannels\Webhook\MailChannelsRequestParser;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\Webhook\Client\RequestParserInterface;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use Symfony\Component\Webhook\Test\AbstractRequestParserTestCase;

#[RequiresPhpExtension('sodium')]
class MailChannelsRequestParserTest extends AbstractRequestParserTestCase
{
    private const CREATED = 1793469600;

    public function testRawBase64PublicKeyIsAccepted()
    {
        $events = $this->createRequestParser()->parse($this->createDeliveredRequest(), base64_encode(sodium_crypto_sign_publickey(self::getKeyPair())));

        $this->assertInstanceOf(MailerDeliveryEvent::class, $events[0]);
    }

    public function testRequestSignedWithSha512DigestIsAccepted()
    {
        $body = $this->getDeliveredPayload();
        $request = $this->createSignedRequest($body, 'sha-512=:'.base64_encode(hash('sha512', $body, true)).':');

        $this->assertCount(1, $this->createRequestParser()->parse($request, $this->getSecret()));
    }

    public function testTamperedBodyIsRejected()
    {
        $body = $this->getDeliveredPayload();
        $request = $this->createSignedRequest($body);
        $request = $this->recreateRequest($request, str_replace('delivered', 'dropped', $body));

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Content digest does not match the body.');

        $this->createRequestParser()->parse($request, $this->getSecret());
    }

    public function testTamperedDigestIsRejected()
    {
        $body = str_replace('delivered', 'dropped', $this->getDeliveredPayload());
        $request = $this->createSignedRequest($this->getDeliveredPayload());
        $request->headers->set('Content-Digest', 'sha-256=:'.base64_encode(hash('sha256', $body, true)).':');
        $request = $this->recreateRequest($request, $body);

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Signature is wrong.');

        $this->createRequestParser()->parse($request, $this->getSecret());
    }

    public function testSignatureOfAnotherKeyIsRejected()
    {
        $otherKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat("\2", \SODIUM_CRYPTO_SIGN_SEEDBYTES)));

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Signature is wrong.');

        $this->createRequestParser()->parse($this->createDeliveredRequest(), base64_encode($otherKey));
    }

    public function testMissingSignatureIsRejected()
    {
        $request = $this->createDeliveredRequest();
        $request->headers->remove('Signature');

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Signature is missing.');

        $this->createRequestParser()->parse($request, $this->getSecret());
    }

    public function testSignatureNotCoveringTheContentDigestIsRejected()
    {
        $body = $this->getDeliveredPayload();
        $request = $this->createSignedRequest($body, null, '("content-type")');

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Signature is wrong.');

        $this->createRequestParser()->parse($request, $this->getSecret());
    }

    public function testUnsupportedDigestAlgorithmIsRejected()
    {
        $body = $this->getDeliveredPayload();
        $request = $this->createSignedRequest($body, 'md5=:'.base64_encode(md5($body, true)).':');

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Content digest is missing.');

        $this->createRequestParser()->parse($request, $this->getSecret());
    }

    public static function provideStaleClockOffsets(): iterable
    {
        yield 'too old' => [301];
        yield 'too far in the future' => [-301];
    }

    #[DataProvider('provideStaleClockOffsets')]
    public function testSignatureOutsideTheTimeWindowIsRejected(int $offset)
    {
        $parser = new MailChannelsRequestParser(new MailChannelsPayloadConverter(), 300, new MockClock('@'.(self::CREATED + $offset)));

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Signature is outside the allowed time window.');

        $parser->parse($this->createDeliveredRequest(), $this->getSecret());
    }

    public function testSignatureAtTheEdgeOfTheTimeWindowIsAccepted()
    {
        $parser = new MailChannelsRequestParser(new MailChannelsPayloadConverter(), 300, new MockClock('@'.(self::CREATED + 300)));

        $this->assertCount(1, $parser->parse($this->createDeliveredRequest(), $this->getSecret()));
    }

    public function testExpiredSignatureIsRejected()
    {
        $body = $this->getDeliveredPayload();
        $request = $this->createSignedRequest($body, null, '("content-digest")', ';created='.self::CREATED.';expires='.(self::CREATED + 10).';alg="ed25519";keyid="mckey"');
        $parser = new MailChannelsRequestParser(new MailChannelsPayloadConverter(), 300, new MockClock('@'.(self::CREATED + 11)));

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Signature has expired.');

        $parser->parse($request, $this->getSecret());
    }

    public function testSignatureOverDerivedComponentsIsAccepted()
    {
        $body = $this->getDeliveredPayload();
        $request = $this->createSignedRequest($body, null, '("@method" "@authority" "@path" "content-digest")');

        $this->assertCount(1, $this->createRequestParser()->parse($request, $this->getSecret()));
    }

    public function testEmptySecretIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createRequestParser()->parse($this->createDeliveredRequest(), '');
    }

    public function testInvalidSecretIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The secret must be the Ed25519 public key of MailChannels');

        $this->createRequestParser()->parse($this->createDeliveredRequest(), 'not-a-key');
    }

    public function testPayloadThatIsNotAListIsRejected()
    {
        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Payload is malformed.');

        $this->createRequestParser()->parse($this->createRequest('{"event": "delivered", "timestamp": 1793469600}'), $this->getSecret());
    }

    public function testUnsupportedEventIsRejected()
    {
        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Unsupported event "unknown".');

        $this->createRequestParser()->parse($this->createRequest('[{"event": "unknown", "timestamp": 1793469600}]'), $this->getSecret());
    }

    protected function createRequestParser(): RequestParserInterface
    {
        return new MailChannelsRequestParser(new MailChannelsPayloadConverter(), 300, new MockClock('@'.self::CREATED));
    }

    protected function getSecret(): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode("\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00".sodium_crypto_sign_publickey(self::getKeyPair())), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    protected function createRequest(string $payload): Request
    {
        return $this->createSignedRequest($payload);
    }

    /**
     * Signs the request as MailChannels does, with a key pair that is generated from a fixed seed.
     */
    private function createSignedRequest(string $payload, ?string $contentDigest = null, string $components = '("content-digest")', ?string $params = null): Request
    {
        $request = Request::create('https://example.com/webhook/mailer_mailchannels', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
        $request->headers->set('Content-Digest', $contentDigest ?? 'sha-256=:'.base64_encode(hash('sha256', $payload, true)).':');

        $signatureParams = $components.($params ?? ';created='.self::CREATED.';alg="ed25519";keyid="mckey"');
        $base = '';
        preg_match_all('/"([^"]+)"/', $components, $matches);
        foreach ($matches[1] as $component) {
            $base .= \sprintf("\"%s\": %s\n", $component, match ($component) {
                '@method' => $request->getMethod(),
                '@authority' => $request->getHttpHost(),
                '@path' => $request->getPathInfo(),
                default => $request->headers->get($component),
            });
        }
        $base .= '"@signature-params": '.$signatureParams;

        $request->headers->set('Signature-Input', 'sig_1='.$signatureParams);
        $request->headers->set('Signature', 'sig_1=:'.base64_encode(sodium_crypto_sign_detached($base, sodium_crypto_sign_secretkey(self::getKeyPair()))).':');

        return $request;
    }

    private function recreateRequest(Request $request, string $payload): Request
    {
        $tampered = Request::create($request->getUri(), 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        foreach (['Content-Digest', 'Signature-Input', 'Signature'] as $name) {
            $tampered->headers->set($name, $request->headers->get($name));
        }

        return $tampered;
    }

    private function createDeliveredRequest(): Request
    {
        return $this->createSignedRequest($this->getDeliveredPayload());
    }

    private function getDeliveredPayload(): string
    {
        return file_get_contents(__DIR__.'/Fixtures/delivered.json');
    }

    private static function getKeyPair(): string
    {
        return sodium_crypto_sign_seed_keypair(str_repeat("\1", \SODIUM_CRYPTO_SIGN_SEEDBYTES));
    }
}
