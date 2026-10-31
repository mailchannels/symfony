<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\MailChannels\RemoteEvent;

use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\RemoteEvent\Exception\ParseException;
use Symfony\Component\RemoteEvent\PayloadConverterInterface;

/**
 * Converts one event of a MailChannels webhook batch.
 *
 * The id of the event is the request id that the Email API returned for the message.
 *
 * @see https://docs.mailchannels.net/email-api/webhook-event-types
 */
final class MailChannelsPayloadConverter implements PayloadConverterInterface
{
    public function convert(array $payload): AbstractMailerEvent
    {
        if (!\is_string($payload['event'] ?? null) || !\is_int($payload['timestamp'] ?? null)) {
            throw new ParseException('Payload is malformed.');
        }

        if (\in_array($payload['event'], ['processed', 'delivered', 'dropped', 'hard-bounced', 'soft-bounced'], true)) {
            $name = match ($payload['event']) {
                'processed' => MailerDeliveryEvent::RECEIVED,
                'delivered' => MailerDeliveryEvent::DELIVERED,
                'dropped' => MailerDeliveryEvent::DROPPED,
                'hard-bounced' => MailerDeliveryEvent::BOUNCE,
                'soft-bounced' => MailerDeliveryEvent::DEFERRED,
            };
            $event = new MailerDeliveryEvent($name, (string) ($payload['request_id'] ?? ''), $payload);
            $event->setReason(trim(($payload['status'] ?? '').' '.($payload['reason'] ?? '')));
        } else {
            $name = match ($payload['event']) {
                'open' => MailerEngagementEvent::OPEN,
                'click' => MailerEngagementEvent::CLICK,
                'complained' => MailerEngagementEvent::SPAM,
                'unsubscribed' => MailerEngagementEvent::UNSUBSCRIBE,
                default => throw new ParseException(\sprintf('Unsupported event "%s".', $payload['event'])),
            };
            $event = new MailerEngagementEvent($name, (string) ($payload['request_id'] ?? ''), $payload);
        }

        $event->setDate(\DateTimeImmutable::createFromTimestamp($payload['timestamp']));
        // The "email" field is the sender, so the recipient comes from the "recipients" list
        $recipient = $payload['recipients'][0] ?? '';
        $event->setRecipientEmail(\is_string($recipient) ? $recipient : '');

        if (\is_string($payload['campaign_id'] ?? null) && '' !== $payload['campaign_id']) {
            $event->setTags([$payload['campaign_id']]);
        }

        return $event;
    }
}
