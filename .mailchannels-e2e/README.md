MailChannels bridge e2e (fork only)
===================================

The behaviour script of [OVERLORD-30](https://mailchannels.atlassian.net/browse/OVERLORD-30)
for the MailChannels Mailer bridge. It lives on the `agent/overlord-30/mailchannels-bridge-e2e`
branch of the MailChannels fork only and is not part of the upstream pull request.

`run-e2e.sh` runs the bridge's PHPUnit suite, sends an email with an attachment from a sample
application (`app/`) with `MAILER_DSN=mailchannels+api://synthetic@default` to a stub Email API
(`stub/`), then posts a signed, a tampered and a `test` webhook to the application.

The stub validates each request against `MailSendBody` of the published OpenAPI spec
(`stub/mail-send-body.schema.json`, extracted from `email-api.yaml` of mailchannels/mc-docs), and
also rejects unknown properties. It serves `https://api.mailchannels.net` on `127.0.0.1:443` with a
throwaway certificate, so the DSN keeps its `default` host; the application resolves the API host
to `127.0.0.1` and trusts that certificate.

Every key, address and payload is synthetic. The webhook key pair comes from a fixed seed.

Locally, with PHP 8.4+ (sodium), python3 with jsonschema, openssl, and permission to listen on port 443:

```sh
COMPOSER_ROOT_VERSION=8.2.x-dev composer update
.mailchannels-e2e/run-e2e.sh
```

The workflow `.github/workflows/mailchannels-bridge-e2e.yml` runs it on every push to an
`agent/overlord-30/` branch.
