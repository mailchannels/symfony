<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\MailChannels\Tests\RemoteEvent;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Bridge\MailChannels\RemoteEvent\MailChannelsPayloadConverter;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\RemoteEvent\Exception\ParseException;

class MailChannelsPayloadConverterTest extends TestCase
{
    #[DataProvider('provideEvents')]
    public function testConvert(string $event, string $class, string $name)
    {
        $converted = (new MailChannelsPayloadConverter())->convert([
            'email' => 'sender@example.com',
            'customer_handle' => 'synthetic-account',
            'timestamp' => 1793469600,
            'event' => $event,
            'request_id' => 'req-1234',
            'recipients' => ['recipient@example.com'],
        ]);

        $this->assertInstanceOf($class, $converted);
        $this->assertSame($name, $converted->getName());
        $this->assertSame('req-1234', $converted->getId());
        $this->assertSame('recipient@example.com', $converted->getRecipientEmail());
        $this->assertEquals(new \DateTimeImmutable('2026-10-31T18:00:00+00:00'), $converted->getDate());
        $this->assertSame([], $converted->getTags());
    }

    public static function provideEvents(): iterable
    {
        yield ['processed', MailerDeliveryEvent::class, MailerDeliveryEvent::RECEIVED];
        yield ['delivered', MailerDeliveryEvent::class, MailerDeliveryEvent::DELIVERED];
        yield ['dropped', MailerDeliveryEvent::class, MailerDeliveryEvent::DROPPED];
        yield ['hard-bounced', MailerDeliveryEvent::class, MailerDeliveryEvent::BOUNCE];
        yield ['soft-bounced', MailerDeliveryEvent::class, MailerDeliveryEvent::DEFERRED];
        yield ['open', MailerEngagementEvent::class, MailerEngagementEvent::OPEN];
        yield ['click', MailerEngagementEvent::class, MailerEngagementEvent::CLICK];
        yield ['complained', MailerEngagementEvent::class, MailerEngagementEvent::SPAM];
        yield ['unsubscribed', MailerEngagementEvent::class, MailerEngagementEvent::UNSUBSCRIBE];
    }

    public function testBounceReasonAndCampaign()
    {
        $converted = (new MailChannelsPayloadConverter())->convert([
            'timestamp' => 1793469600,
            'event' => 'soft-bounced',
            'request_id' => 'req-1234',
            'status' => '421',
            'reason' => 'try again later',
            'campaign_id' => 'welcome',
        ]);

        $this->assertInstanceOf(MailerDeliveryEvent::class, $converted);
        $this->assertSame('421 try again later', $converted->getReason());
        $this->assertSame(['welcome'], $converted->getTags());
        $this->assertSame('', $converted->getRecipientEmail());
    }

    public function testUnsupportedEvent()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unsupported event "unknown".');

        (new MailChannelsPayloadConverter())->convert(['event' => 'unknown', 'timestamp' => 1793469600]);
    }

    public function testMalformedPayload()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Payload is malformed.');

        (new MailChannelsPayloadConverter())->convert(['event' => 'delivered', 'timestamp' => '2026-10-31']);
    }
}
