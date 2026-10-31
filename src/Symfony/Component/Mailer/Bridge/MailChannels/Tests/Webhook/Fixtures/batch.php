<?php

use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;

$payload = json_decode(file_get_contents(str_replace('.php', '.json', __FILE__)), true, flags: JSON_THROW_ON_ERROR);

$processed = new MailerDeliveryEvent(MailerDeliveryEvent::RECEIVED, 'req-1', $payload[0]);
$processed->setRecipientEmail('recipient@example.com');
$processed->setDate(\DateTimeImmutable::createFromTimestamp(1793469600));
$processed->setReason('');

$bounced = new MailerDeliveryEvent(MailerDeliveryEvent::BOUNCE, 'req-1', $payload[1]);
$bounced->setRecipientEmail('recipient@example.com');
$bounced->setDate(\DateTimeImmutable::createFromTimestamp(1793469660));
$bounced->setReason('550 5.1.1 mailbox does not exist');

$click = new MailerEngagementEvent(MailerEngagementEvent::CLICK, 'req-2', $payload[2]);
$click->setRecipientEmail('other@example.com');
$click->setDate(\DateTimeImmutable::createFromTimestamp(1793469720));

return [$processed, $bounced, $click];
