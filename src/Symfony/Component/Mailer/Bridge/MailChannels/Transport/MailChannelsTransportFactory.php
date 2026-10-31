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

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class MailChannelsTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        $scheme = $dsn->getScheme();

        if ('mailchannels' === $scheme || 'mailchannels+api' === $scheme) {
            $host = 'default' === $dsn->getHost() ? null : $dsn->getHost();

            return (new MailChannelsApiTransport($this->getUser($dsn), $this->client, $this->dispatcher, $this->logger))
                ->setHost($host)
                ->setPort($dsn->getPort());
        }

        if ('mailchannels+smtp' === $scheme) {
            return new MailChannelsSmtpTransport($this->getUser($dsn), $this->getPassword($dsn), $this->dispatcher, $this->logger);
        }

        throw new UnsupportedSchemeException($dsn, 'mailchannels', $this->getSupportedSchemes());
    }

    protected function getSupportedSchemes(): array
    {
        return ['mailchannels', 'mailchannels+api', 'mailchannels+smtp'];
    }
}
