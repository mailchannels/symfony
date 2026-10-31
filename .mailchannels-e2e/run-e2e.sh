#!/usr/bin/env bash
# Behaviour script of OVERLORD-30 for the MailChannels Mailer bridge, on synthetic data only:
#  1. the bridge's PHPUnit suite passes;
#  2. with MAILER_DSN=mailchannels+api://synthetic@default, an email with an attachment
#     reaches the stub Email API as one request that is valid against the OpenAPI spec;
#  3. a signed webhook results in a "delivered" MailerDeliveryEvent;
#  4. a tampered webhook is rejected.
# Needs PHP >= 8.4 with sodium, python3 with jsonschema, openssl, and root or
# CAP_NET_BIND_SERVICE: the stub listens on 127.0.0.1:443 so that the DSN keeps its "default" host.
# Run "composer update" at the root of the checkout first.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
E2E="$ROOT/.mailchannels-e2e"
WORK=$(mktemp -d)
export E2E_VAR_DIR="$WORK/var"
mkdir -p "$E2E_VAR_DIR"
PIDS=()
trap 'kill "${PIDS[@]}" 2>/dev/null || true' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }
step() { echo; echo "== $*"; }

wait_for_port() {
    for _ in $(seq 1 50); do
        (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null && return 0
        sleep 0.2
    done
    fail "nothing listens on port $1"
}

step "1. Bridge PHPUnit suite"
(cd "$ROOT" && php ./phpunit src/Symfony/Component/Mailer/Bridge/MailChannels) || fail "the bridge's PHPUnit suite failed"

step "2. Send through MAILER_DSN=mailchannels+api://synthetic@default to the stub Email API"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=api.mailchannels.net" \
    -addext "subjectAltName=DNS:api.mailchannels.net" -keyout "$WORK/key.pem" -out "$WORK/cert.pem" 2>/dev/null
python3 "$E2E/stub/stub.py" --port 443 --cert "$WORK/cert.pem" --key "$WORK/key.pem" --log "$WORK/requests.jsonl" &
PIDS+=($!)
wait_for_port 443

export MAILER_DSN='mailchannels+api://synthetic@default'
export STUB_CA_FILE="$WORK/cert.pem"
MAILCHANNELS_WEBHOOK_PUBLIC_KEY=$(php "$E2E/app/bin/webhook.php" public-key)
export MAILCHANNELS_WEBHOOK_PUBLIC_KEY

php "$E2E/app/bin/send.php" || fail "sending failed"
[ -f "$WORK/requests.jsonl" ] || fail "the stub received no valid request"
[ "$(wc -l < "$WORK/requests.jsonl")" -eq 1 ] || fail "the stub received $(wc -l < "$WORK/requests.jsonl") valid requests, expected 1"
python3 - "$WORK/requests.jsonl" <<'PY' || fail "the request does not carry the email"
import base64, json, sys
request = json.loads(open(sys.argv[1]).read())
body = request['body']
assert request['headers'].get('X-Api-Key') == 'synthetic', request['headers']
assert body['personalizations'] == [{'to': [{'email': 'recipient@example.com'}]}], body['personalizations']
assert body['from'] == {'email': 'sender@example.com', 'name': 'E2E Sender'}, body['from']
assert [c['type'] for c in body['content']] == ['text/plain', 'text/html'], body['content']
[attachment] = body['attachments']
assert attachment['filename'] == 'report.csv' and attachment['type'] == 'text/csv', attachment
assert base64.b64decode(attachment['content']) == b'id,value\n1,synthetic\n', attachment
print('the stub received one valid request with the attachment:', json.dumps(body)[:300], '...')
PY

python3 - "$WORK/cert.pem" <<'PY' || fail "the stub accepts a request that the OpenAPI spec forbids"
import json, ssl, sys, urllib.error, urllib.request
context = ssl.create_default_context(cafile=sys.argv[1])
request = urllib.request.Request('https://127.0.0.1/tx/v1/send', data=json.dumps({'from': {'email': 'sender@example.com'}, 'subject': 'x', 'content': []}).encode(),
                                 headers={'Content-Type': 'application/json', 'X-Api-Key': 'synthetic', 'Host': 'api.mailchannels.net'})
context.check_hostname = False
try:
    urllib.request.urlopen(request, context=context)
except urllib.error.HTTPError as e:
    assert e.code == 400, e.code
    print('the stub rejects a body without personalizations:', e.read().decode())
else:
    raise AssertionError('accepted')
PY
[ "$(wc -l < "$WORK/requests.jsonl")" -eq 1 ] || fail "the invalid request was logged"

step "3. A signed webhook results in a delivered MailerDeliveryEvent"
php -S 127.0.0.1:8000 "$E2E/app/public/index.php" > "$WORK/server.log" 2>&1 &
PIDS+=($!)
wait_for_port 8000
URL=http://127.0.0.1:8000/webhook/mailchannels

status=$(php "$E2E/app/bin/webhook.php" post "$URL" delivered)
[ "$status" = 202 ] || fail "the signed webhook got $status, expected 202"
grep -q '"class":"Symfony\\\\Component\\\\RemoteEvent\\\\Event\\\\Mailer\\\\MailerDeliveryEvent","name":"delivered","id":"e2e-request","recipient":"recipient@example.com"' "$E2E_VAR_DIR/events.jsonl" \
    || fail "no delivered MailerDeliveryEvent was consumed: $(cat "$E2E_VAR_DIR/events.jsonl" 2>/dev/null)"
cat "$E2E_VAR_DIR/events.jsonl"

step "4. A tampered webhook is rejected"
status=$(php "$E2E/app/bin/webhook.php" post "$URL" delivered tampered)
[ "$status" = 403 ] || fail "the tampered webhook got $status, expected 403"
[ "$(wc -l < "$E2E_VAR_DIR/events.jsonl")" -eq 1 ] || fail "the tampered webhook produced an event"
echo "rejected with 403, no event"

step "5. The endpoint validation event of MailChannels is accepted"
status=$(php "$E2E/app/bin/webhook.php" post "$URL" test)
[ "$status" = 202 ] || fail "the test event got $status, expected 202"
echo "accepted with 202"

echo
echo "PASS"
