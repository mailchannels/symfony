<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\MailChannels\Tests\Transport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\MailChannels\Transport\MailChannelsApiTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Header\TrackingHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Contracts\HttpClient\ResponseInterface;

class MailChannelsApiTransportTest extends TestCase
{
    #[DataProvider('getTransportData')]
    public function testToString(MailChannelsApiTransport $transport, string $expected)
    {
        $this->assertSame($expected, (string) $transport);
    }

    public static function getTransportData(): iterable
    {
        yield [
            new MailChannelsApiTransport('KEY'),
            'mailchannels+api://api.mailchannels.net',
        ];

        yield [
            (new MailChannelsApiTransport('KEY'))->setHost('example.com'),
            'mailchannels+api://example.com',
        ];

        yield [
            (new MailChannelsApiTransport('KEY'))->setHost('example.com')->setPort(99),
            'mailchannels+api://example.com:99',
        ];
    }

    public function testSend()
    {
        $email = (new Email())
            ->from(new Address('foo@example.com', 'Ms. Foo Bar'))
            ->to(new Address('bar@example.com', 'Mr. Recipient'), 'baz@example.com')
            ->cc('cc@example.com')
            ->bcc(new Address('bcc@example.com', 'Bcc'))
            ->replyTo(new Address('reply@example.com', 'Ms. Reply'))
            ->subject('An email')
            ->text('Test email body')
            ->html('<html lang="en"><body><p>Test email body</p></body></html>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<https://example.com/unsubscribe>');

        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.mailchannels.net/tx/v1/send', $url);
            $this->assertContains('X-Api-Key: KEY', $options['headers']);

            $this->assertSame([
                'personalizations' => [[
                    'to' => [['email' => 'bar@example.com', 'name' => 'Mr. Recipient'], ['email' => 'baz@example.com']],
                    'cc' => [['email' => 'cc@example.com']],
                    'bcc' => [['email' => 'bcc@example.com', 'name' => 'Bcc']],
                ]],
                'from' => ['email' => 'foo@example.com', 'name' => 'Ms. Foo Bar'],
                'subject' => 'An email',
                'content' => [
                    ['type' => 'text/plain', 'value' => 'Test email body'],
                    ['type' => 'text/html', 'value' => '<html lang="en"><body><p>Test email body</p></body></html>'],
                ],
                'reply_to' => ['email' => 'reply@example.com', 'name' => 'Ms. Reply'],
                'headers' => ['List-Unsubscribe' => '<https://example.com/unsubscribe>'],
            ], json_decode($options['body'], true));

            return new JsonMockResponse([
                'request_id' => 'req-1234',
                'results' => [['index' => 0, 'message_id' => 'msg-1', 'status' => 'sent']],
            ], ['http_code' => 202]);
        });

        $sentMessage = (new MailChannelsApiTransport('KEY', $client))->send($email);

        $this->assertSame('req-1234', $sentMessage->getMessageId());
    }

    public function testSendWithAttachmentAndInlinePart()
    {
        $email = (new Email())
            ->from('foo@example.com')
            ->to('bar@example.com')
            ->subject('An email')
            ->html('<img src="cid:logo.png">')
            ->addPart(new DataPart('%PDF-1.4', 'invoice.pdf', 'application/pdf'))
            ->addPart((new DataPart('PNG', 'logo.png', 'image/png'))->asInline())
            ->addPart((new DataPart('GIF', 'banner.gif', 'image/gif'))->asInline()->setContentId('banner@example.com'));

        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $payload = json_decode($options['body'], true);

            $this->assertSame([['type' => 'text/html', 'value' => '<img src="cid:logo.png">']], $payload['content']);
            $this->assertSame([
                ['content' => base64_encode('%PDF-1.4'), 'filename' => 'invoice.pdf', 'type' => 'application/pdf'],
                ['content' => base64_encode('PNG'), 'filename' => 'logo.png', 'type' => 'image/png', 'content_id' => 'logo.png'],
                ['content' => base64_encode('GIF'), 'filename' => 'banner.gif', 'type' => 'image/gif', 'content_id' => 'banner@example.com'],
            ], $payload['attachments']);

            return new JsonMockResponse(['request_id' => 'req-1234', 'results' => [['index' => 0, 'status' => 'sent']]], ['http_code' => 202]);
        });

        (new MailChannelsApiTransport('KEY', $client))->send($email);
    }

    public function testSendWithStreamedBodies()
    {
        $text = fopen('php://memory', 'r+');
        fwrite($text, 'Streamed text');
        $html = fopen('php://memory', 'r+');
        fwrite($html, '<p>Streamed HTML</p>');

        $email = (new Email())
            ->from('foo@example.com')
            ->to('bar@example.com')
            ->subject('An email')
            ->text($text)
            ->html($html);

        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertSame([
                ['type' => 'text/plain', 'value' => 'Streamed text'],
                ['type' => 'text/html', 'value' => '<p>Streamed HTML</p>'],
            ], json_decode($options['body'], true)['content']);

            return new JsonMockResponse(['request_id' => 'req-1234'], ['http_code' => 202]);
        });

        (new MailChannelsApiTransport('KEY', $client))->send($email);
    }

    public function testSendWithTagMetadataAndTracking()
    {
        $email = (new Email())
            ->from('foo@example.com')
            ->to('bar@example.com')
            ->subject('An email')
            ->text('Test email body');
        $email->getHeaders()
            ->add(new TagHeader('password-reset'))
            ->add(new MetadataHeader('user-id', '42'))
            ->add(new TrackingHeader(opens: false, clicks: true));

        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $payload = json_decode($options['body'], true);

            $this->assertSame('password-reset', $payload['campaign_id']);
            $this->assertSame(['X-Metadata-user-id' => '42'], $payload['headers']);
            $this->assertSame(['open_tracking' => ['enable' => false], 'click_tracking' => ['enable' => true]], $payload['tracking_settings']);

            return new JsonMockResponse(['request_id' => 'req-1234'], ['http_code' => 202]);
        });

        (new MailChannelsApiTransport('KEY', $client))->send($email);
    }

    public function testTrackingIsLeftToTheAccountWithoutTrackingHeader()
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $payload = json_decode($options['body'], true);

            $this->assertArrayNotHasKey('tracking_settings', $payload);
            $this->assertArrayNotHasKey('headers', $payload);

            return new JsonMockResponse(['request_id' => 'req-1234'], ['http_code' => 202]);
        });

        (new MailChannelsApiTransport('KEY', $client))->send((new Email())->from('foo@example.com')->to('bar@example.com')->subject('An email')->text('Test email body'));
    }

    public function testSendWithAnEnvelopeSenderOfItsOwn()
    {
        $email = (new Email())
            ->from('foo@example.com')
            ->to('bar@example.com')
            ->subject('An email')
            ->text('Test email body');
        $envelope = new Envelope(new Address('bounces@example.com'), [new Address('envelope@example.com')]);

        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $payload = json_decode($options['body'], true);

            $this->assertSame(['email' => 'foo@example.com'], $payload['from']);
            $this->assertSame(['email' => 'bounces@example.com'], $payload['envelope_from']);
            $this->assertSame([['to' => [['email' => 'envelope@example.com']]]], $payload['personalizations']);

            return new JsonMockResponse(['request_id' => 'req-1234'], ['http_code' => 202]);
        });

        (new MailChannelsApiTransport('KEY', $client))->send($email, $envelope);
    }

    public function testSendWithSeveralTagsThrows()
    {
        $email = (new Email())->from('foo@example.com')->to('bar@example.com')->subject('An email')->text('Test email body');
        $email->getHeaders()->add(new TagHeader('one'))->add(new TagHeader('two'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('MailChannels only allows a single tag per email.');

        (new MailChannelsApiTransport('KEY', new MockHttpClient()))->send($email);
    }

    public function testSendWithSeveralReplyToAddressesThrows()
    {
        $email = (new Email())->from('foo@example.com')->to('bar@example.com')->replyTo('a@example.com', 'b@example.com')->subject('An email')->text('Test email body');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('MailChannels only allows a single "Reply-To" address per email.');

        (new MailChannelsApiTransport('KEY', new MockHttpClient()))->send($email);
    }

    public function testSendThrowsForErrorResponse()
    {
        $client = new MockHttpClient(new JsonMockResponse(['errors' => ['Domain example.com is not allowed to send: Domain Lockdown check failed']], ['http_code' => 403]));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email: Domain example.com is not allowed to send: Domain Lockdown check failed (code 403).');

        (new MailChannelsApiTransport('KEY', $client))->send((new Email())->from('foo@example.com')->to('bar@example.com')->subject('An email')->text('Test email body'));
    }

    public function testSendThrowsForFailedResult()
    {
        $client = new MockHttpClient(new JsonMockResponse([
            'request_id' => 'req-1234',
            'results' => [['index' => 0, 'status' => 'failed', 'reason' => 'recipient is suppressed']],
        ], ['http_code' => 202]));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email: recipient is suppressed (code 202).');

        (new MailChannelsApiTransport('KEY', $client))->send((new Email())->from('foo@example.com')->to('bar@example.com')->subject('An email')->text('Test email body'));
    }

    public function testSendThrowsForErrorResponseWithContentTypeTextHtml()
    {
        $client = new MockHttpClient(new MockResponse('<html><body>Bad gateway</body></html>', [
            'http_code' => 502,
            'response_headers' => ['content-type' => 'text/html'],
        ]));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email: <html><body>Bad gateway</body></html> (code 502).');

        (new MailChannelsApiTransport('KEY', $client))->send((new Email())->from('foo@example.com')->to('bar@example.com')->subject('An email')->text('Test email body'));
    }
}
