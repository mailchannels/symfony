<?php

use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;

$wh = new MailerDeliveryEvent(MailerDeliveryEvent::DELIVERED, 'wBLWrCnK0Z965pf-cgxhNg8bo5s=', json_decode(file_get_contents(str_replace('.php', '.json', __FILE__)), true, flags: JSON_THROW_ON_ERROR)[0]);
$wh->setRecipientEmail('recipient@example.com');
$wh->setTags(['password-reset']);
$wh->setDate(\DateTimeImmutable::createFromTimestamp(1793469600));
$wh->setReason('');

return [$wh];
