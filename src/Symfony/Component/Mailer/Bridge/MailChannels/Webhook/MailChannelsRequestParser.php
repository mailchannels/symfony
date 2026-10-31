<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\MailChannels\Webhook;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\Mailer\Bridge\MailChannels\RemoteEvent\MailChannelsPayloadConverter;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\LogicException;
use Symfony\Component\RemoteEvent\Exception\ParseException;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Verifies and parses the webhooks of MailChannels.
 *
 * MailChannels signs each request with Ed25519 as described by RFC 9421, over a Content-Digest header (RFC 9530) of the body.
 * The secret is the public key of MailChannels, as returned by https://api.mailchannels.net/tx/v1/webhook/public-key?id=mckey:
 * either the PEM document or the base64 encoding of the raw 32-byte key.
 *
 * @see https://docs.mailchannels.net/email-api/webhooks#verifying-message-signatures
 */
final class MailChannelsRequestParser extends AbstractRequestParser
{
    // The DER prefix of an Ed25519 SubjectPublicKeyInfo, which the 32 bytes of the key follow
    private const ED25519_SPKI_PREFIX = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";

    private const DIGEST_ALGORITHMS = ['sha-256' => 'sha256', 'sha-512' => 'sha512'];

    public function __construct(
        private readonly MailChannelsPayloadConverter $converter,
        private readonly int $tolerance = 300,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher('POST'),
            new IsJsonRequestMatcher(),
        ]);
    }

    /**
     * @return RemoteEvent[]
     */
    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): array
    {
        if (!$secret) {
            throw new InvalidArgumentException('A non-empty secret is required: the public key that MailChannels signs its webhooks with.');
        }

        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            throw new LogicException('The "sodium" PHP extension is required to verify the signature of MailChannels webhooks.');
        }

        $this->verifySignature($request, $this->decodePublicKey($secret));
        $this->verifyContentDigest($request);

        $payload = $request->toArray();
        if (!$payload || !array_is_list($payload)) {
            throw new RejectWebhookException(406, 'Payload is malformed.');
        }

        $events = [];
        foreach ($payload as $item) {
            if (!\is_array($item) || !\is_string($item['event'] ?? null)) {
                throw new RejectWebhookException(406, 'Payload is malformed.');
            }

            // MailChannels sends a test event when the endpoint is validated, which expects a successful response
            if ('test' === $item['event']) {
                $events[] = new RemoteEvent('test', (string) ($item['request_id'] ?? ''), $item);

                continue;
            }

            try {
                $events[] = $this->converter->convert($item);
            } catch (ParseException $e) {
                throw new RejectWebhookException(406, $e->getMessage(), $e);
            }
        }

        return $events;
    }

    private function verifySignature(Request $request, string $publicKey): void
    {
        $signatures = [];
        preg_match_all('/([a-z*][a-z0-9_.*-]*)=:([A-Za-z0-9+\/]*={0,2}):/i', $request->headers->get('Signature', ''), $matches, \PREG_SET_ORDER);
        foreach ($matches as [, $label, $value]) {
            $signatures[$label] = base64_decode($value, true);
        }

        // Each member is "label=(components);params", the part after "label=" being the value of "@signature-params"
        preg_match_all('/([a-z*][a-z0-9_.*-]*)=(\(([^)]*)\)((?:;[a-z*][a-z0-9_.*-]*=(?:"[^"]*"|[^;,\s]*))*))/i', $request->headers->get('Signature-Input', ''), $matches, \PREG_SET_ORDER);

        if (!$matches || !$signatures) {
            throw new RejectWebhookException(403, 'Signature is missing.');
        }

        foreach ($matches as [, $label, $signatureParams, $components, $params]) {
            if (!isset($signatures[$label]) || false === $signatures[$label]) {
                continue;
            }

            preg_match_all('/;([a-z*][a-z0-9_.*-]*)=("[^"]*"|[^;]*)/i', $params, $paramMatches, \PREG_SET_ORDER);
            $params = [];
            foreach ($paramMatches as [, $key, $value]) {
                $params[$key] = trim($value, '"');
            }

            if (isset($params['alg']) && 'ed25519' !== $params['alg']) {
                continue;
            }

            if (!preg_match('/^(?:\s*"[a-z0-9@-]+")*\s*$/', $components) || !preg_match_all('/"([^"]+)"/', $components, $componentMatches)) {
                continue;
            }

            // The digest binds the signature to the body
            if (!\in_array('content-digest', $componentMatches[1], true)) {
                continue;
            }

            $base = '';
            foreach ($componentMatches[1] as $component) {
                if (null === $value = $this->getComponentValue($request, $component)) {
                    continue 2;
                }
                $base .= \sprintf("\"%s\": %s\n", $component, $value);
            }
            $base .= '"@signature-params": '.$signatureParams;

            if (\SODIUM_CRYPTO_SIGN_BYTES !== \strlen($signatures[$label]) || !sodium_crypto_sign_verify_detached($signatures[$label], $base, $publicKey)) {
                continue;
            }

            $this->verifyTimestamps($params);

            return;
        }

        throw new RejectWebhookException(403, 'Signature is wrong.');
    }

    private function verifyTimestamps(array $params): void
    {
        $now = $this->clock?->now()->getTimestamp() ?? time();

        if (!ctype_digit($params['created'] ?? '') || abs($now - (int) $params['created']) > $this->tolerance) {
            throw new RejectWebhookException(403, 'Signature is outside the allowed time window.');
        }

        if (isset($params['expires']) && (!ctype_digit($params['expires']) || $now > (int) $params['expires'])) {
            throw new RejectWebhookException(403, 'Signature has expired.');
        }
    }

    private function verifyContentDigest(Request $request): void
    {
        $body = $request->getContent();
        $verified = false;

        preg_match_all('/([a-z0-9-]+)=:([A-Za-z0-9+\/]*={0,2}):/i', $request->headers->get('Content-Digest', ''), $matches, \PREG_SET_ORDER);
        foreach ($matches as [, $algorithm, $digest]) {
            if (!isset(self::DIGEST_ALGORITHMS[$algorithm = strtolower($algorithm)])) {
                continue;
            }

            if (!hash_equals(hash(self::DIGEST_ALGORITHMS[$algorithm], $body, true), (string) base64_decode($digest, true))) {
                throw new RejectWebhookException(403, 'Content digest does not match the body.');
            }

            $verified = true;
        }

        if (!$verified) {
            throw new RejectWebhookException(403, 'Content digest is missing.');
        }
    }

    private function getComponentValue(Request $request, string $component): ?string
    {
        $requestUri = $request->server->get('REQUEST_URI', '');
        $query = parse_url($requestUri, \PHP_URL_QUERY);

        return match ($component) {
            '@method' => $request->getMethod(),
            '@scheme' => $request->getScheme(),
            '@authority' => strtolower($request->getHttpHost()),
            '@path' => parse_url($requestUri, \PHP_URL_PATH) ?: '/',
            '@query' => '?'.($query ?? ''),
            '@request-target' => $requestUri,
            '@target-uri' => $request->getSchemeAndHttpHost().$requestUri,
            default => str_starts_with($component, '@') || !$request->headers->has($component)
                ? null
                : implode(', ', array_map(trim(...), $request->headers->all($component))),
        };
    }

    private function decodePublicKey(#[\SensitiveParameter] string $secret): string
    {
        $key = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $secret), true);

        if (false !== $key && 44 === \strlen($key) && str_starts_with($key, self::ED25519_SPKI_PREFIX)) {
            $key = substr($key, 12);
        }

        if (false === $key || \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($key)) {
            throw new InvalidArgumentException('The secret must be the Ed25519 public key of MailChannels, as a PEM document or base64-encoded.');
        }

        return $key;
    }
}
