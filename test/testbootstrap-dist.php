<?php

/**
 * PHPMailer live SMTP test configuration.
 *
 * Copy this file to testbootstrap.php and configure for your SMTP server.
 * For local development, use MailHog:
 *   docker compose up -d
 *   Then access the web UI at http://localhost:8025
 *
 * @see https://github.com/mailhog/MailHog
 */

$_REQUEST['submitted'] = 1;
$_REQUEST['mail_to'] = 'somebody@example.com';
$_REQUEST['mail_from'] = 'phpunit@example.com';
$_REQUEST['mail_cc'] = 'cc@example.com';
$_REQUEST['mail_host'] = 'localhost';
$_REQUEST['mail_port'] = 1025;  # MailHog default; for smtp-sink use 2500
