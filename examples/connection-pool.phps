<?php

/**
 * SMTP connection pooling.
 *
 * Caches connected + authenticated SMTP sessions across many `send` calls
 * so each new message skips the TCP/TLS/AUTH handshake. Process-local —
 * in a Workerman worker each worker keeps its own pool.
 *
 * **Important caveat:** PROXY-protocol pins a connection to a specific
 * peer at connect time. Pooling + PROXY-per-request is **not safe** —
 * either bypass the pool while PROXY is on, or include the peer identity
 * in your pool key so different clients get different connections. The
 * example below assumes PROXY is OFF.
 */

use PHPMailer\PHPMailer\Async\SmtpConnectionPool;
use PHPMailer\PHPMailer\Async\TransportFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Etc/UTC');

$host = 'smtp.example.com';
$port = 25;
$user = 'alice@example.com';
$pass = getenv('SMTP_PASSWORD') ?: 'change-me';

// One pool per worker (or per script). Tune limits to your relay's idle
// timeout and your concurrency.
$pool = new SmtpConnectionPool(
    maxPerKey:        8,
    idleTimeoutSec:   60.0,
    useNoopHealthCheck: true
);

$poolKey = sprintf('%s:%d:%s', $host, $port, $user);

// --------------------------- send one message ---------------------------

$smtp = $pool->acquireOrNew($poolKey, static function (): SMTP {
    // Factory only runs on a pool miss.
    $s = new SMTP();
    $s->setTransport(TransportFactory::auto());
    return $s;
});

// Distinguish "fresh from factory" (not connected) from "warm from pool"
// (already connected + authenticated).
if (!$smtp->connected()) {
    if (!$smtp->connect($host, $port, 5)) {
        die("connect failed: " . $smtp->getLastReply() . "\n");
    }
    $smtp->hello('me.example.com');
    if (!$smtp->authenticate($user, $pass)) {
        die("auth failed: " . $smtp->getLastReply() . "\n");
    }
}

// Drive the message via PHPMailer for proper MIME / address handling, or
// directly via SMTP::mail/recipient/data for raw RFC822.
$mail = new PHPMailer(true);
$mail->setSMTPInstance($smtp);
$mail->isSMTP();
$mail->Host = $host;
$mail->Port = $port;
$mail->setFrom($user, 'Alice');
$mail->addAddress('bob@example.com', 'Bob');
$mail->Subject = 'Pooled hello';
$mail->Body    = 'Sent over a pooled, already-authenticated SMTP session.';

try {
    $mail->preSend();
    if (!$mail->send()) {
        die($mail->ErrorInfo . "\n");
    }
} finally {
    // RSET-and-stash; the next acquireOrNew() with the same key will reuse
    // this session, skipping the full handshake.
    $pool->release($poolKey, $smtp);
}

// --------------------------- observability ------------------------------

$stats = $pool->stats();
printf(
    "pool: %d hits, %d misses (%.1f%% hit-rate), %d releases, %d evictions, %d idle now\n",
    $stats['acquireHits'],
    $stats['acquireMisses'],
    $stats['hitRatio'] * 100,
    $stats['releases'],
    $stats['evictions'],
    $stats['idleNow']
);

// --------------------------- shutdown -----------------------------------
//
// In a long-lived Workerman worker, call $pool->closeAll() from
// `onWorkerStop` so live sessions get a polite QUIT before the worker
// dies. In a short script, the OS reclaims sockets on exit anyway, but
// closeAll() is the polite version.
$pool->closeAll();
