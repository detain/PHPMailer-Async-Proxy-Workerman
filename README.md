# PHPMailer-Async-Proxy-Workerman

[![Tests](https://github.com/detain/PHPMailer-Async-Proxy-Workerman/actions/workflows/tests.yml/badge.svg)](https://github.com/detain/PHPMailer-Async-Proxy-Workerman/actions/workflows/tests.yml)
[![Static analysis](https://github.com/detain/PHPMailer-Async-Proxy-Workerman/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/detain/PHPMailer-Async-Proxy-Workerman/actions/workflows/static-analysis.yml)
[![License](https://img.shields.io/badge/license-LGPL--2.1-blue.svg)](LICENSE)

A drop-in PHPMailer fork for **Workerman / Webman** projects that need
**non-blocking SMTP send** and **first-class PROXY Protocol v1+v2** support.

- Same public API as upstream PHPMailer 7.x — `$mail->send()` still looks
  synchronous to callers, fibers handle the yielding under the hood.
- Every byte of socket I/O routes through a pluggable `Transport`. Use the
  blocking `StreamTransport` (default, byte-for-byte equivalent to upstream)
  or the async `WorkermanTransport` (Workerman 5 + Revolt event-loop + PHP
  Fibers) inside a worker.
- PROXY Protocol v1 *and* v2 — the relay sees the real client IP without
  HAProxy in front.

## Why

The parent project ([MailBaby Mail API](https://github.com/detain/mailbaby-mail-api))
runs as a Workerman worker pool fronting ZoneMTA. Stock PHPMailer uses blocking
`stream_socket_client` / `fwrite` / `fgets`, so every outbound SMTP send stalls
the worker that's handling the inbound HTTP request — concurrency is capped at
the worker count.

This fork rewrites the transport layer to suspend on a Revolt event-loop
between every read and write. The same worker can fan out many concurrent
SMTP sessions and the rest of PHPMailer's feature set
(MIME / attachments / DKIM / S/MIME / OAuth / encodings / localization)
is untouched.

## Install

```sh
composer require mailbaby/phpmailer-async-proxy-workerman
```

Requirements: PHP 8.1+ (uses Fibers). `ext-openssl` for TLS/STARTTLS;
`ext-sockets` is optional — when present, `WorkermanTransport` uses it to
surface specific async-connect errors via `SO_ERROR`. Everything works
without it.

## Quick start (drop-in)

If you don't need the async path, this is a stock PHPMailer 7.x:

```php
use PHPMailer\PHPMailer\PHPMailer;

$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = 'smtp.example.com';
$mail->Port = 25;
$mail->setFrom('alice@example.com');
$mail->addAddress('bob@example.com');
$mail->Subject = 'Hi';
$mail->Body = 'Hello';
$mail->send();
```

## PROXY Protocol

```php
use PHPMailer\PHPMailer\ProxyProtocol\Configurator;

// v1 (text):
$mail->setProxyProtocol(
    Configurator::v1($clientIp, $serverIp, $clientPort, 25)
);

// v2 (binary, smaller frame, less ambiguity):
$mail->setProxyProtocol(
    Configurator::v2($clientIp, $serverIp, $clientPort, 25)
);

// Disable for this send:
$mail->setProxyProtocol(null);
```

The header is written immediately after TCP connect and before any SMTP
traffic — exactly where HAProxy, ZoneMTA, nginx-stream, AWS NLB etc.
expect it. Auto-detects IPv4 vs IPv6 from the source IP.

## Async transport (Workerman / Webman)

```php
use PHPMailer\PHPMailer\Async\WorkermanTransport;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\ProxyProtocol\Configurator;

// Inside a Workerman worker (or any code running under a Revolt event-loop):
$smtp = new SMTP();
$smtp->setTransport(new WorkermanTransport());

$mail = new PHPMailer(true);
$mail->setSMTPInstance($smtp);
$mail->isSMTP();
$mail->Host = 'smtp.example.com';
$mail->setProxyProtocol(Configurator::v1($clientIp, '10.0.0.1', $clientPort, 25));
$mail->setFrom('alice@example.com');
$mail->addAddress('bob@example.com');
$mail->Subject = 'Hi';
$mail->Body = 'Hello';
$mail->send(); // Yields to the event-loop on every read/write
```

The library works whether you're inside a Workerman event-loop or just running
PHPUnit / CLI — `FiberRunner` spins up a private Revolt loop on demand. That's
why the upstream PHPMailer test suite passes against this fork unchanged.

## Compatibility

| Component   | Version                                          |
|-------------|--------------------------------------------------|
| PHP         | 8.1 / 8.2 / 8.3 / 8.4                            |
| Workerman   | ^5.0 (optional but required for async)           |
| Revolt      | ^1.0                                             |
| PHPUnit     | ^9.6 (upstream PHPMailer tests)                  |
| PHPStan     | ^1.11 (level 5 on src/Async + src/ProxyProtocol) |

## Tests

```sh
composer test                          # full suite (blocking transport)
vendor/bin/phpunit test/Async/         # async transport
vendor/bin/phpunit test/ProxyProtocol/ # PROXY v1+v2
vendor/bin/phpstan analyse             # static analysis
composer check                         # phpcs (PSR-12)
```

## Upstream PHPMailer

This fork tracks upstream
[PHPMailer/PHPMailer](https://github.com/PHPMailer/PHPMailer) 7.0.2 as its base.
All credit for the email composition, address parsing, DKIM, S/MIME, attachment,
encoding and localization layers belongs to the upstream maintainers (Marcus
Bointon, Jim Jagielski, Andy Prevost, Brent R. Matzelle, and contributors). Only
the SMTP transport layer is new. The full upstream README is preserved in
[`docs/UPSTREAM.md`](docs/UPSTREAM.md) for reference.

## License

LGPL-2.1 (matches upstream PHPMailer), with the
[GPL Cooperation Commitment](https://gplcc.github.io/gplcc/). See
[LICENSE](LICENSE) and [COMMITMENT](COMMITMENT).
