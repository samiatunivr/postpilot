<?php
declare(strict_types=1);

/** Send a plain-text email via PHP mail(). Returns true on success. */
function send_mail(string $to, string $subject, string $body, string $from = 'no-reply@proctordesk.local'): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $headers = "From: {$from}\r\n"
             . "Reply-To: {$from}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n"
             . "X-Mailer: ProctorDesk\r\n";
    $subject = preg_replace('/[\r\n]+/', ' ', $subject) ?? '';
    return @mail($to, $subject, $body, $headers);
}
