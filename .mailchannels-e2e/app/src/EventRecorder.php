<?php

namespace App;

use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Records the remote events of the MailChannels webhook, for run-e2e.sh to check.
 */
#[AsRemoteEventConsumer('mailchannels')]
final class EventRecorder implements ConsumerInterface
{
    public function __construct(
        private readonly string $file,
    ) {
    }

    public function consume(RemoteEvent $event): void
    {
        file_put_contents($this->file, json_encode([
            'class' => $event::class,
            'name' => $event->getName(),
            'id' => $event->getId(),
            'recipient' => $event instanceof AbstractMailerEvent ? $event->getRecipientEmail() : null,
        ])."\n", \FILE_APPEND);
    }
}
