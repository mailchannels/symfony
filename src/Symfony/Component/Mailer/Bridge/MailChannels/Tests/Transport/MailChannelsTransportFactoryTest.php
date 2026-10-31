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

use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Bridge\MailChannels\Transport\MailChannelsApiTransport;
use Symfony\Component\Mailer\Bridge\MailChannels\Transport\MailChannelsSmtpTransport;
use Symfony\Component\Mailer\Bridge\MailChannels\Transport\MailChannelsTransportFactory;
use Symfony\Component\Mailer\Test\AbstractTransportFactoryTestCase;
use Symfony\Component\Mailer\Test\IncompleteDsnTestTrait;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;

class MailChannelsTransportFactoryTest extends AbstractTransportFactoryTestCase
{
    use IncompleteDsnTestTrait;

    public function getFactory(): TransportFactoryInterface
    {
        return new MailChannelsTransportFactory(null, new MockHttpClient(), new NullLogger());
    }

    public static function supportsProvider(): iterable
    {
        yield [
            new Dsn('mailchannels', 'default'),
            true,
        ];

        yield [
            new Dsn('mailchannels+api', 'default'),
            true,
        ];

        yield [
            new Dsn('mailchannels+smtp', 'default'),
            true,
        ];

        yield [
            new Dsn('mailchannels+smtps', 'default'),
            false,
        ];
    }

    public static function createProvider(): iterable
    {
        $logger = new NullLogger();

        yield [
            new Dsn('mailchannels', 'default', self::USER),
            new MailChannelsApiTransport(self::USER, new MockHttpClient(), null, $logger),
        ];

        yield [
            new Dsn('mailchannels+api', 'default', self::USER),
            new MailChannelsApiTransport(self::USER, new MockHttpClient(), null, $logger),
        ];

        yield [
            new Dsn('mailchannels+api', 'example.com', self::USER, null, 8080),
            (new MailChannelsApiTransport(self::USER, new MockHttpClient(), null, $logger))->setHost('example.com')->setPort(8080),
        ];

        yield [
            new Dsn('mailchannels+smtp', 'default', self::USER, self::PASSWORD),
            new MailChannelsSmtpTransport(self::USER, self::PASSWORD, null, $logger),
        ];
    }

    public static function unsupportedSchemeProvider(): iterable
    {
        yield [
            new Dsn('mailchannels+foo', 'default', self::USER),
            'The "mailchannels+foo" scheme is not supported; supported schemes for mailer "mailchannels" are: "mailchannels", "mailchannels+api", "mailchannels+smtp".',
        ];

        yield [
            new Dsn('mailchannels+smtps', 'default', self::USER, self::PASSWORD),
            'The "mailchannels+smtps" scheme is not supported; supported schemes for mailer "mailchannels" are: "mailchannels", "mailchannels+api", "mailchannels+smtp".',
        ];
    }

    public static function incompleteDsnProvider(): iterable
    {
        yield [new Dsn('mailchannels+api', 'default')];
        yield [new Dsn('mailchannels+smtp', 'default', self::USER)];
        yield [new Dsn('mailchannels+smtp', 'default')];
    }
}
