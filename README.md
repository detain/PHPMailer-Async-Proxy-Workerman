# PHPMailer-Async-Proxy-Workerman

[![Tests](https://github.com/detain/PHPMailer-Async-Proxy-Workerman/actions/workflows/tests.yml/badge.svg)](https://github.com/detain/PHPMailer-Async-Proxy-Workerman/actions/workflows/tests.yml)
[![Static analysis](https://github.com/detain/PHPMailer-Async-Proxy-Workerman/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/detain/PHPMailer-Async-Proxy-Workerman/actions/workflows/static-analysis.yml)
[![License](https://img.shields.io/badge/license-LGPL--2.1-blue.svg)](LICENSE)

A drop-in [PHPMailer](https://github.com/PHPMailer/PHPMailer) fork for **Workerman / Webman**
projects that need **non-blocking SMTP send** and **first-class PROXY Protocol v1+v2** support.

- Same public API as upstream PHPMailer — `$mail->send()` still looks
  synchronous to callers; Fibers handle the yielding under the hood.
- Every byte of socket I/O routes through a pluggable `Transport`. Use the
  blocking `StreamTransport` (default, byte-for-byte equivalent to upstream)
  or the async `WorkermanTransport` (Workerman 5 + Revolt event-loop + PHP
  Fibers) inside a worker.
- PROXY Protocol v1 *and* v2 — the relay sees the real client IP without
  HAProxy in front.
- Connection pooling for high-volume senders.
- Full upstream PHPMailer feature set: MIME / attachments / DKIM / S/MIME /
  OAuth / encodings / localization — all untouched.

## Features

- Integrated SMTP support – send without a local mail server
- Send emails with multiple To, CC, BCC, and Reply-to addresses
- Multipart/alternative emails for mail clients that do not read HTML email
- Add attachments, including inline
- Support for UTF-8 content and 8bit, base64, binary, and quoted-printable encodings
- Full UTF-8 support when using servers that support `SMTPUTF8`
- Support for iCal events in multiparts and attachments
- SMTP authentication with `LOGIN`, `PLAIN`, `CRAM-MD5`, and `XOAUTH2` mechanisms
  over SMTPS and SMTP+STARTTLS transports
- Validates email addresses automatically
- Protects against header injection attacks
- Error messages in over 50 languages
- DKIM and S/MIME signing support
- PROXY Protocol v1 and v2 support
- Non-blocking async transport via Workerman/Revolt/PHP Fibers
- Connection pooling for process-local SMTP session reuse
- Compatible with PHP 8.1 – 8.4

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
composer require detain/phpmailer-async-proxy-workerman
```

Requirements: PHP 8.1+ (uses Fibers). `ext-openssl` for TLS/STARTTLS;
`ext-sockets` is optional — when present, `WorkermanTransport` uses it to
surface specific async-connect errors via `SO_ERROR`. Everything works
without it.

## Quick start

### Drop-in (same as upstream PHPMailer)

If you don't need the async path, this is a stock PHPMailer:

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

### PROXY Protocol

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

### Async transport (Workerman / Webman)

There are three transports; the factory picks the best one for the
current runtime so most consumers never have to know the difference:

```php
use PHPMailer\PHPMailer\Async\TransportFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\ProxyProtocol\Configurator;

$smtp = new SMTP();
$smtp->setTransport(TransportFactory::auto());

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

`TransportFactory::auto()` chooses:

| Runtime | Transport | Notes |
|---------|-----------|-------|
| Real Workerman worker hosting the call | `WorkermanConnectionTransport` | Uses Workerman's own `AsyncTcpConnection`; plugs into the worker's event loop, stats, lifecycle. |
| Revolt available, no worker | `WorkermanTransport` | Raw non-blocking streams + Revolt watchers. Works in any Revolt context including PHPUnit / CLI. |
| Neither | `StreamTransport` | Blocking, byte-for-byte equivalent to upstream PHPMailer. |

`TransportFactory` also exposes forced helpers: `blocking()`, `revoltDirect()`, `workermanConnection()`.

`WorkermanConnectionTransport` requires a long-lived event-loop / fiber context: in a real worker the loop is always alive; in PHPUnit / CLI wrap the **whole SMTP session** in one `FiberRunner::run(...)`. The other transports work without that constraint.

The library works whether you're inside a Workerman event-loop or just running PHPUnit / CLI — `FiberRunner` spins up a private Revolt loop on demand. That's why the upstream PHPMailer test suite passes against this fork unchanged.

### Connection pooling

For high-volume senders that don't need PROXY-protocol-per-request, the
fork ships a process-local SMTP pool that caches connected + authenticated
sessions across calls:

```php
use PHPMailer\PHPMailer\Async\SmtpConnectionPool;
use PHPMailer\PHPMailer\Async\TransportFactory;
use PHPMailer\PHPMailer\SMTP;

$pool = new SmtpConnectionPool(maxPerKey: 8, idleTimeoutSec: 60);

$key = "smtp.example.com:25:alice";
$smtp = $pool->acquireOrNew($key, function () {
    $s = new SMTP();
    $s->setTransport(TransportFactory::auto());
    return $s;
});

if (!$smtp->connected()) {
    // Pool miss — full handshake
    $smtp->connect('smtp.example.com', 25, 5);
    $smtp->hello('me.example.com');
    $smtp->authenticate('alice', $pass);
}
// ... mail() / recipient() / data() ...
$pool->release($key, $smtp);   // RSET + keep for the next call
```

**PROXY + pool don't mix.** A pooled connection shipped its PROXY header once
at connect time, advertising a specific peer. Reusing it for a *different*
client would silently misreport the source IP. The pool has no PROXY
awareness — callers that flip PROXY per request should either include the
peer identity in their pool key, or — much simpler — skip the pool while
PROXY is on. The mailbaby-mail-api integration takes that route via the
`mail.connection_pool_enabled` config flag, which is hard-disabled whenever
PROXY is on.

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

## Localization

PHPMailer defaults to English, but in the `language/` folder you'll find many
translations for PHPMailer error messages. Their filenames contain [ISO 639-1](https://en.wikipedia.org/wiki/ISO_639-1)
language code for the translations, for example `fr` for French. To specify a language:

```php
// Load the French version
$mail->setLanguage('fr', '/optional/path/to/language/directory/');
```

## Documentation

Complete generated API documentation is [available online](https://phpmailer.github.io/PHPMailer/).

Generate it locally:

```sh
phpdoc -c phpdoc.dist.xml
```

Documentation will appear in the `docs/` folder.

Examples of how to use PHPMailer for common scenarios can be found in the
`examples/` folder. For a good starting point, see `examples/smtp.phps`.

## Upgrading

See [UPGRADING.md](UPGRADING.md) for instructions on:
- [Upgrading from upstream PHPMailer 7.x to this fork](UPGRADING.md#upgrading-from-upstream-phpmailer-70x-to-phpmailer-async-proxy-workerman-800-async0)
- [Upgrading from PHPMailer 5.2](UPGRADING.md#upgrading-from-phpmailer-52)

## Contributing

Please submit bug reports, suggestions, and pull requests to the
[GitHub issue tracker](https://github.com/detain/PHPMailer-Async-Proxy-Workerman/issues).

We're particularly interested in fixing edge cases, expanding test coverage,
and updating translations.

## Security

Please disclose any vulnerabilities found responsibly — report security issues
to the maintainers privately via the [GitHub security advisories](https://github.com/detain/PHPMailer-Async-Proxy-Workerman/security/advisories).

See also [SECURITY.md](SECURITY.md).

## Upstream PHPMailer

This fork tracks upstream [PHPMailer/PHPMailer](https://github.com/PHPMailer/PHPMailer)
as its base. All credit for the email composition, address parsing, DKIM,
S/MIME, attachment, encoding and localization layers belongs to the upstream
maintainers (Marcus Bointon, Jim Jagielski, Andy Prevost, Brent R. Matzelle,
and contributors). Only the SMTP transport layer is new.

PHPMailer was originally written in 2001 by Brent R. Matzelle as a
SourceForge project. [Marcus Bointon](https://github.com/Synchro) and Andy
Prevost took over in 2004. The project moved to GitHub in 2013 under the
[PHPMailer organisation](https://github.com/PHPMailer).

## License

LGPL-2.1 (matches upstream PHPMailer), with the
[GPL Cooperation Commitment](https://gplcc.github.io/gplcc/). See
[LICENSE](LICENSE) and [COMMITMENT](COMMITMENT).
