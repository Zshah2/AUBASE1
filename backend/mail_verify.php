<?php
declare(strict_types=1);

/**
 * Sends “verify your email” link. Requires AUBASE_MAIL_FROM and AUBASE_BASE_URL in .env for real delivery.
 */
function aubase_send_verification_email(string $to, string $name, string $token): bool
{
    $from = getenv('AUBASE_MAIL_FROM') ?: '';
    if ($from === '') {
        return false;
    }

    $base = rtrim((string) (getenv('AUBASE_BASE_URL') ?: 'http://localhost:8080'), '/');
    $link = $base . '/verify.php?token=' . urlencode($token);
    $safeName = $name !== '' ? $name : 'there';

    $subject = 'Verify your AuBase account';
    $body = "Hi {$safeName},\r\n\r\nPlease confirm your email to activate your AuBase account:\r\n\r\n{$link}\r\n\r\nThis link expires in 48 hours.\r\n\r\nIf you didn’t sign up, you can ignore this email.\r\n\r\n— AuBase\r\n";

    $headers = [
        'From: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
    ];

    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

/**
 * Sends password reset link. Requires AUBASE_MAIL_FROM and AUBASE_BASE_URL.
 */
function aubase_send_password_reset_email(string $to, string $name, string $token): bool
{
    $from = getenv('AUBASE_MAIL_FROM') ?: '';
    if ($from === '') {
        return false;
    }

    $base = rtrim((string) (getenv('AUBASE_BASE_URL') ?: 'http://localhost:8080'), '/');
    $link = $base . '/reset_password.php?token=' . urlencode($token);
    $safeName = $name !== '' ? $name : 'there';

    $subject = 'Reset your AuBase password';
    $body = "Hi {$safeName},\r\n\r\nWe received a request to reset your AuBase password. Use this link (valid for 1 hour):\r\n\r\n{$link}\r\n\r\nIf you didn’t ask for this, you can ignore this email.\r\n\r\n— AuBase\r\n";

    $headers = [
        'From: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
    ];

    return @mail($to, $subject, $body, implode("\r\n", $headers));
}
