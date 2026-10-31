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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Bridge\MailChannels\Transport\MailChannelsSmtpTransport;

class MailChannelsSmtpTransportTest extends TestCase
{
    public function testItRelaysThroughTheMailChannelsServerWithStartTls()
    {
        $transport = new MailChannelsSmtpTransport('ACCOUNT_ID', 'SMTP_PASSWORD');

        $this->assertSame('smtp://smtp.mailchannels.net:587', (string) $transport);
        $this->assertSame('ACCOUNT_ID', $transport->getUsername());
        $this->assertSame('SMTP_PASSWORD', $transport->getPassword());
        $this->assertFalse($transport->getStream()->isTLS());
    }
}
