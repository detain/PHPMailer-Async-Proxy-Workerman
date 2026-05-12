<?php

/**
 * SMTP connection pooling.
 *
 * Caches connected + authenticated SMTP sessions across many `send` calls
 * so each new message skips the TCP/TLS/AUTH handshake. Process-local —
 * in a Workerman worker each worker keeps its own pool.
 *
 * ## CRITICAL: SMTPKeepAlive must be true
 *
 * `PHPMailer::send()` calls `smtpClose()` at the end of every send. The
 * default behaviour is to issue QUIT and close the socket — meaning the
 * SMTP instance you'd hand back to the pool is now dead and the next
 * acquire will be a miss. Set `$mail->SMTPKeepAlive = true` and PHPMailer
 * leaves the connection open instead, so `$pool->release()` actually
 * caches a usable session. Without this flag the pool's idleNow stays at
 * 0 and every send pays the full handshake cost.
 *
 * ## CRITICAL: PROXY + pool don't mix
 *
 * PROXY-protocol pins a connection to a specific peer at connect time.
 * Pooling + PROXY-per-request is unsafe — either bypass the pool while
 * PROXY is on, or include the peer identity in your pool key so different
 * clients get different connections. The example below assumes PROXY is OFF.
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
    maxPerKey:          8,
    idleTimeoutSec:     60.0,
    useNoopHealthCheck: true
);

$poolKey = sprintf('%s:%d:%s', $host, $port, $user);

// --------------------------- send several messages ----------------------

foreach (range(1, 3) as $i) {
    $smtp = $pool->acquireOrNew($poolKey, static function (): SMTP {
        // Factory only runs on a pool miss.
        $s = new SMTP();
        $s->setTransport(TransportFactory::auto());
        return $s;
    });

    // `connected() === false` means the factory just ran — do the full
    // handshake. `connected() === true` means we got a session from the
    // pool and can skip it.
    if (!$smtp->connected()) {
        if (!$smtp->connect($host, $port, 5)) {
            die("connect failed: " . $smtp->getLastReply() . "\n");
        }
        $smtp->hello('me.example.com');
        if (!$smtp->authenticate($user, $pass)) {
            die("auth failed: " . $smtp->getLastReply() . "\n");
        }
    }

    $mail = new PHPMailer(true);
    $mail->setSMTPInstance($smtp);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->SMTPAuth = true;
    $mail->Username = $user;
    $mail->Password = $pass;
    // ★ Without this, PHPMailer::smtpClose() will QUIT + close the socket
    //   right after send() — and you'd hand a dead session back to the pool.
    $mail->SMTPKeepAlive = true;
    $mail->setFrom($user, 'Alice');
    $mail->addAddress('bob@example.com', 'Bob');
    $mail->Subject = "Pooled hello #{$i}";
    $mail->Body    = "Sent over a pooled, already-authenticated SMTP session.";

    try {
        $mail->preSend();
        if (!$mail->send()) {
            echo "send failed: " . $mail->ErrorInfo . "\n";
            // Pool will see !connected() if PHPMailer tore the session down
            // due to a fatal error — release() then drops it; the next
            // acquireOrNew() will get a fresh factory call.
        } else {
            echo "sent #{$i}; ";
        }
    } finally {
        // RSET-and-stash. With SMTPKeepAlive=true the SMTP is still
        // connected here, so this actually pools the session.
        $pool->release($poolKey, $smtp);
    }
}

echo "\n";

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
// First iteration: 1 miss + 1 release.
// Subsequent iterations: 1 hit + 1 release each.
// With N=3: expect 1 miss, 2 hits, 3 releases.

// --------------------------- shutdown -----------------------------------
//
// In a long-lived Workerman worker, call $pool->closeAll() from
// `onWorkerStop` so live sessions get a polite QUIT before the worker
// dies. In a short script, the OS reclaims sockets on exit anyway, but
// closeAll() is the polite version.
$pool->closeAll();

// =================================================================
// Alternative: drive the SMTP dialogue directly (bypassing PHPMailer)
// =================================================================
//
// For raw RFC822 sends — e.g. forwarding pre-signed DKIM messages — you
// can call mail() / recipient() / data() directly on the pooled $smtp
// without going through PHPMailer at all. No SMTPKeepAlive concern there
// because PHPMailer's smtpClose() never runs.
//
//   $smtp->mail($from);
//   $smtp->recipient($to);
//   $smtp->data($rfc822Body);
//   $pool->release($poolKey, $smtp);
