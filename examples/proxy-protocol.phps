<?php

/**
 * PROXY Protocol v1 + v2 with PHPMailer.
 *
 * Shows the three flavours of PROXY-protocol configuration the fork
 * supports. The header is written immediately after TCP connect and
 * before any SMTP traffic — exactly where HAProxy, ZoneMTA,
 * nginx-stream, AWS NLB etc. expect it.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\ProxyProtocol\Configurator;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Etc/UTC');

$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = 'relay.example.com';
$mail->Port = 25;

$clientIp   = '203.0.113.45'; // end-user IP forwarded via X-Forwarded-For etc.
$clientPort = 54321;
$serverIp   = '10.0.0.1';     // this API host's IP as the relay sees it
$relayPort  = 25;

// --- 1) Text-mode v1 — auto-detects IPv4 vs IPv6 from $clientIp ----------
$mail->setProxyProtocol(
    Configurator::v1($clientIp, $serverIp, $clientPort, $relayPort)
);

// --- 2) Binary v2 — smaller frame, less ambiguous --------------------------
$mail->setProxyProtocol(
    Configurator::v2($clientIp, $serverIp, $clientPort, $relayPort)
);

// --- 3) No peer info available -- emit the spec-mandated UNKNOWN form ----
// Use when you need to participate in the protocol but cannot/should not
// share the real client identity for this connection.
$mail->setProxyProtocol(Configurator::v1Unknown());

// --- 4) Disable for this send --------------------------------------------
$mail->setProxyProtocol(null);

// Sanity: which builder is currently active?
$builder = $mail->getSMTPInstance()->getProxyProtocolBuilder();
echo $builder === null
    ? "PROXY protocol disabled\n"
    : "Active PROXY v{$builder->version()} header: " . trim($builder->build()) . "\n";

// Normal PHPMailer flow continues unchanged.
$mail->setFrom('alice@example.com', 'Alice');
$mail->addAddress('bob@example.com', 'Bob');
$mail->Subject = 'PROXY-aware test';
$mail->Body    = 'Sent with the real client IP forwarded through PROXY protocol.';

// $mail->send();
