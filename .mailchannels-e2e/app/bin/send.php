<?php

// Sends one email with an attachment through MAILER_DSN

use App\Kernel;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

require \dirname(__DIR__).'/autoload.php';

$kernel = new Kernel('prod', false);
$kernel->boot();

$email = (new Email())
    ->from(new Address('sender@example.com', 'E2E Sender'))
    ->to('recipient@example.com')
    ->subject('MailChannels bridge e2e')
    ->text('Synthetic message sent by the MailChannels bridge e2e test.')
    ->html('<p>Synthetic message sent by the <b>MailChannels</b> bridge e2e test.</p>')
    ->attach("id,value\n1,synthetic\n", 'report.csv', 'text/csv');

$kernel->getContainer()->get('app.mailer')->send($email);

echo "sent\n";
