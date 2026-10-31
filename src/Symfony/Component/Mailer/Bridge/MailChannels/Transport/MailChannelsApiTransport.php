<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\MailChannels\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Header\TrackingHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends through the MailChannels Email API.
 *
 * @see https://docs.mailchannels.net/email-api/api-reference/send-an-email
 */
final class MailChannelsApiTransport extends AbstractApiTransport
{
    private const HOST = 'api.mailchannels.net';

    /**
     * Headers that the payload already contains, that Symfony generates or that the API refuses. Other headers are sent as custom headers.
     */
    private const PAYLOAD_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'subject', 'reply-to', 'sender', 'date', 'message-id', 'return-path',
        'content-type', 'content-transfer-encoding', 'mime-version', 'x-track',
    ];

    public function __construct(
        #[\SensitiveParameter] private readonly string $key,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return \sprintf('mailchannels+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $response = $this->client->request('POST', 'https://'.$this->getEndpoint().'/tx/v1/send', [
            'headers' => [
                'Accept' => 'application/json',
                'X-Api-Key' => $this->key,
            ],
            'json' => $this->getPayload($email, $envelope),
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).\sprintf(' (code %d).', $response->getStatusCode()), $response);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote MailChannels server.', $response, 0, $e);
        }

        if (200 !== $statusCode && 202 !== $statusCode) {
            throw new HttpTransportException('Unable to send an email: '.$this->getErrorMessage($result, $response).\sprintf(' (code %d).', $statusCode), $response);
        }

        // The message has a single personalization, so its result is the first one
        $sendResult = $result['results'][0] ?? null;

        if ('failed' === ($sendResult['status'] ?? null)) {
            throw new HttpTransportException('Unable to send an email: '.($sendResult['reason'] ?? 'the message was not accepted').\sprintf(' (code %d).', $statusCode), $response);
        }

        // Webhook events carry the request id, so it is what correlates a sent message with its events
        if (isset($result['request_id'])) {
            $sentMessage->setMessageId((string) $result['request_id']);
        }

        return $response;
    }

    private function getPayload(Email $email, Envelope $envelope): array
    {
        $from = $email->getFrom()[0] ?? $envelope->getSender();

        $personalization = ['to' => array_map($this->formatAddress(...), $this->getRecipients($email, $envelope))];

        if ($cc = $email->getCc()) {
            $personalization['cc'] = array_map($this->formatAddress(...), $cc);
        }

        if ($bcc = $email->getBcc()) {
            $personalization['bcc'] = array_map($this->formatAddress(...), $bcc);
        }

        $payload = [
            'personalizations' => [$personalization],
            'from' => $this->formatAddress($from),
            'subject' => $email->getSubject() ?? '',
            'content' => [],
        ];

        if ($envelope->getSender()->getAddress() !== $from->getAddress()) {
            $payload['envelope_from'] = $this->formatAddress($envelope->getSender());
        }

        if ($replyTo = $email->getReplyTo()) {
            if (\count($replyTo) > 1) {
                throw new TransportException('MailChannels only allows a single "Reply-To" address per email.');
            }

            $payload['reply_to'] = $this->formatAddress($replyTo[0]);
        }

        // The API expects the text part before the HTML one
        if (null !== $text = $this->bodyToString($email->getTextBody())) {
            $payload['content'][] = ['type' => 'text/plain', 'value' => $text];
        }

        if (null !== $html = $this->bodyToString($email->getHtmlBody())) {
            $payload['content'][] = ['type' => 'text/html', 'value' => $html];
        }

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');

            $part = [
                'content' => base64_encode($attachment->getBody()),
                'filename' => $filename,
                'type' => $headers->get('Content-Type')->getBody(),
            ];

            if ('inline' === $headers->getHeaderBody('Content-Disposition')) {
                $part['content_id'] = $attachment->hasContentId() ? $attachment->getContentId() : $filename;
            }

            $payload['attachments'][] = $part;
        }

        if ($tracking = TrackingHeader::fromHeaders($email->getHeaders())) {
            if (null !== $tracking->getOpens()) {
                $payload['tracking_settings']['open_tracking']['enable'] = $tracking->getOpens();
            }
            if (null !== $tracking->getClicks()) {
                $payload['tracking_settings']['click_tracking']['enable'] = $tracking->getClicks();
            }
        }

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (\in_array($name, self::PAYLOAD_HEADERS, true)) {
                continue;
            }

            // Webhook events report the campaign id, which makes it the closest match for a tag
            if ($header instanceof TagHeader) {
                if (isset($payload['campaign_id'])) {
                    throw new TransportException('MailChannels only allows a single tag per email.');
                }

                $payload['campaign_id'] = $header->getValue();

                continue;
            }

            // The API has no metadata field: a MetadataHeader is sent as its "X-Metadata-*" header
            $payload['headers'][$header->getName()] = $header->getBodyAsString();
        }

        return $payload;
    }

    /**
     * Mail bodies may be streams, which the JSON encoding cannot take.
     *
     * @param resource|string|null $body
     */
    private function bodyToString(mixed $body): ?string
    {
        if (!\is_resource($body)) {
            return $body;
        }

        if (stream_get_meta_data($body)['seekable']) {
            rewind($body);
        }

        $contents = stream_get_contents($body);

        return false === $contents ? '' : $contents;
    }

    private function formatAddress(Address $address): array
    {
        $formatted = ['email' => $address->getAddress()];

        if ('' !== $address->getName()) {
            $formatted['name'] = $address->getName();
        }

        return $formatted;
    }

    private function getErrorMessage(array $result, ResponseInterface $response): string
    {
        // The API documents its errors as a list of strings
        if (\is_array($result['errors'] ?? null) && $errors = array_filter($result['errors'], \is_string(...))) {
            return implode('; ', $errors);
        }

        return $response->getContent(false);
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST).($this->port ? ':'.$this->port : '');
    }
}
