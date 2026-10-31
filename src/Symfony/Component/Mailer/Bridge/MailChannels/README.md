MailChannels Bridge
===================

Provides [MailChannels](https://www.mailchannels.com) integration for Symfony Mailer.

Configuration example:

```env
# API
MAILER_DSN=mailchannels+api://KEY@default

# SMTP
MAILER_DSN=mailchannels+smtp://ACCOUNT_ID:SMTP_PASSWORD@default
```

where:
 - `KEY` is your MailChannels Email API key
 - `ACCOUNT_ID` is your MailChannels account id
 - `SMTP_PASSWORD` is an SMTP password created in the MailChannels console

`mailchannels://KEY@default` is the same as `mailchannels+api://KEY@default`.

The API transport supports attachments, inline parts, custom headers, the
`TrackingHeader` (open and click tracking) and a single `TagHeader`, sent as the
`campaign_id` of the message. A `MetadataHeader` is sent as its `X-Metadata-*`
header. The message id of the sent message is the request id that the API
returns, which webhook events carry too.

Webhook
-------

Configure the webhook routing with the public key of MailChannels as the secret,
either the PEM document returned by
`https://api.mailchannels.net/tx/v1/webhook/public-key?id=mckey` or the base64
encoding of the raw key:

```yaml
framework:
    webhook:
        routing:
            mailer_mailchannels:
                service: 'mailer.webhook.request_parser.mailchannels'
                secret: '%env(MAILCHANNELS_WEBHOOK_PUBLIC_KEY)%'
```

The parser verifies the Ed25519 signature (RFC 9421) and the `Content-Digest` of
each request, and rejects signatures created more than 5 minutes away from the
current time. It requires the `sodium` PHP extension. A request carries a batch
of events, so it results in several remote events. The `test` event sent when
the endpoint is validated becomes a `RemoteEvent` named `test`.

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/symfony/issues) and
   [send Pull Requests](https://github.com/symfony/symfony/pulls)
   in the [main Symfony repository](https://github.com/symfony/symfony)
