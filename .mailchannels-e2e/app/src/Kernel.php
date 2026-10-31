<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A minimal application that sends through MAILER_DSN and receives MailChannels webhooks on /webhook/mailchannels.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return getenv('E2E_VAR_DIR').'/cache';
    }

    public function getLogDir(): string
    {
        return getenv('E2E_VAR_DIR').'/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'synthetic']);

        // "default" resolves to api.mailchannels.net: send it to the stub, which presents a certificate for that name
        $container->extension('http_client', ['default_options' => [
            'resolve' => ['api.mailchannels.net' => '127.0.0.1'],
            'cafile' => '%env(STUB_CA_FILE)%',
        ]]);

        $container->extension('mailer', ['dsn' => '%env(MAILER_DSN)%']);

        $container->extension('webhook', ['routing' => [
            'mailchannels' => [
                'service' => 'mailer.webhook.request_parser.mailchannels',
                'secret' => '%env(MAILCHANNELS_WEBHOOK_PUBLIC_KEY)%',
            ],
        ]]);

        $container->services()
            ->set(EventRecorder::class)
                ->autoconfigure()
                ->arg('$file', '%env(E2E_VAR_DIR)%/events.jsonl')
            ->alias('app.mailer', MailerInterface::class)->public()
        ;
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@FrameworkBundle/Resources/config/routing/webhook.php')->prefix('/webhook');
    }
}
