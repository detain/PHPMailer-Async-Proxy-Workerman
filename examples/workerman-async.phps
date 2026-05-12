<?php

/**
 * Async SMTP send inside a Workerman worker.
 *
 * Demonstrates wiring the WorkermanTransport into PHPMailer so each SMTP
 * read/write yields to the worker's event loop instead of stalling the
 * process. Drop a copy in your worker boot and it Just Works — the public
 * PHPMailer API is unchanged.
 *
 * Run with:
 *
 *     php examples/workerman-async.phps start
 *
 * The worker listens on tcp://127.0.0.1:18787 and sends a test mail per
 * incoming connection (anything that opens a TCP connection triggers a
 * send). Use this only as a reference shape — wire it into your real
 * worker boot, not as a production daemon.
 */

use PHPMailer\PHPMailer\Async\FiberRunner;
use PHPMailer\PHPMailer\Async\WorkermanTransport;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\ProxyProtocol\Configurator;
use PHPMailer\PHPMailer\SMTP;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Etc/UTC');

$worker = new Worker('tcp://127.0.0.1:18787');
$worker->count = 4;
$worker->name  = 'async-mail-demo';

$worker->onMessage = static function (TcpConnection $connection, $data): void {
    $mail = new PHPMailer(true);

    // Wire the async transport BEFORE any other SMTP setup so setProxyProtocol()
    // lands on the WorkermanTransport-backed SMTP, not a lazily-created
    // StreamTransport.
    $smtp = new SMTP();
    $smtp->setTransport(new WorkermanTransport());
    $mail->setSMTPInstance($smtp);

    $mail->isSMTP();
    $mail->Host     = 'smtp.example.com';
    $mail->Port     = 25;
    $mail->SMTPAuth = true;
    $mail->Username = 'alice@example.com';
    $mail->Password = getenv('SMTP_PASSWORD') ?: 'change-me';
    $mail->SMTPDebug = SMTP::DEBUG_OFF;

    // Forward the real remote IP via PROXY Protocol v1 so the relay sees
    // the original peer, not this worker's IP. Pass v2(...) if your relay
    // prefers the binary frame format.
    $mail->setProxyProtocol(Configurator::v1(
        $connection->getRemoteIp(),
        '10.0.0.1',                       // this server's IP as the relay sees it
        (int) $connection->getRemotePort(),
        25
    ));

    $mail->setFrom('alice@example.com', 'Alice');
    $mail->addAddress('bob@example.com', 'Bob');
    $mail->Subject = 'Hello from a Workerman worker';
    $mail->Body    = "Body sent at " . date('c');

    try {
        // The send is wrapped in a single fiber. Internally the transport
        // yields on every read/write so other connections continue to make
        // progress through this same worker.
        $ok = FiberRunner::run(static fn(): bool => $mail->send());
        $connection->send($ok ? "+OK queued\n" : "-ERR {$mail->ErrorInfo}\n");
    } catch (Throwable $t) {
        $connection->send("-ERR " . $t->getMessage() . "\n");
    }
    $connection->close();
};

Worker::runAll();
