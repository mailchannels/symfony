"""Stub of the MailChannels Email API send endpoint, for the bridge e2e test.

It validates each POST /tx/v1/send body against MailSendBody from the published OpenAPI
spec (mail-send-body.schema.json), stricter in one way: unknown properties are rejected,
so that a misspelled field fails the test instead of being ignored. Valid requests are
appended to the log file as JSON lines and answered like the API: 202 with a request id.
"""

import argparse
import json
import ssl
import sys
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import jsonschema

SCHEMA = json.loads((Path(__file__).parent / 'mail-send-body.schema.json').read_text())


def strict(schema):
    if isinstance(schema, dict):
        if schema.get('type') == 'object' and 'properties' in schema:
            schema.setdefault('additionalProperties', False)
        for value in schema.values():
            strict(value)
    elif isinstance(schema, list):
        for value in schema:
            strict(value)
    return schema


# "headers" and "dynamic_template_data" are free-form maps without "properties", so they stay open
VALIDATOR = jsonschema.Draft7Validator(strict(SCHEMA))


class Handler(BaseHTTPRequestHandler):
    log_file = None
    api_key = None

    def _reply(self, status, body):
        data = json.dumps(body).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_POST(self):
        if self.path.split('?')[0] != '/tx/v1/send':
            return self._reply(404, {'errors': ['not found']})
        if self.headers.get('X-Api-Key') != self.api_key:
            return self._reply(401, {'errors': ['invalid API key']})
        try:
            body = json.loads(self.rfile.read(int(self.headers.get('Content-Length', 0))))
        except ValueError as e:
            return self._reply(400, {'errors': ['invalid JSON: %s' % e]})
        errors = ['%s: %s' % ('/'.join(map(str, e.absolute_path)) or '(root)', e.message) for e in VALIDATOR.iter_errors(body)]
        if errors:
            print('stub: rejected request: %s' % errors, file=sys.stderr, flush=True)
            return self._reply(400, {'errors': errors})
        request_id = 'stub-' + uuid.uuid4().hex
        with open(self.log_file, 'a') as f:
            f.write(json.dumps({'request_id': request_id, 'headers': dict(self.headers), 'body': body}) + '\n')
        self._reply(202, {'request_id': request_id, 'results': [{'index': 0, 'message_id': request_id + '@stub', 'status': 'sent'}]})

    def log_message(self, fmt, *args):
        print('stub: ' + fmt % args, file=sys.stderr, flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=443)
    parser.add_argument('--cert')
    parser.add_argument('--key')
    parser.add_argument('--api-key', default='synthetic')
    parser.add_argument('--log', required=True)
    args = parser.parse_args()

    Handler.log_file = args.log
    Handler.api_key = args.api_key
    server = ThreadingHTTPServer((args.host, args.port), Handler)
    if args.cert:
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(args.cert, args.key)
        server.socket = context.wrap_socket(server.socket, server_side=True)
    print('stub: listening on %s:%d' % (args.host, args.port), file=sys.stderr, flush=True)
    server.serve_forever()


if __name__ == '__main__':
    main()
