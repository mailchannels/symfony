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
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Relays through the SMTP server of MailChannels, with STARTTLS on port 587.
 *
 * The username is the MailChannels account id and the password an SMTP password created in the console.
 *
 * @see https://docs.mailchannels.net/email-api/smtp-sending
 */
final class MailChannelsSmtpTransport extends EsmtpTransport
{
    public function __construct(string $accountId, #[\SensitiveParameter] string $password, ?EventDispatcherInterface $dispatcher = null, ?LoggerInterface $logger = null)
    {
        parent::__construct('smtp.mailchannels.net', 587, false, $dispatcher, $logger);

        $this->setUsername($accountId);
        $this->setPassword($password);
    }
}
