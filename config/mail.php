<?php
/**
 * Mail configuration and the send_mail() helper used across the app.
 *
 * If vendor/autoload.php (installed via `composer require phpmailer/phpmailer`)
 * is present, PHPMailer is used over SMTP with the credentials below.
 * Otherwise the app falls back to PHP's built-in mail() so local/dev
 * environments without SMTP configured still function — every send is
 * logged to email_logs either way.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

/**
 * Send an HTML email and record the outcome in email_logs.
 *
 * @param string      $type    Short machine tag, e.g. verify_email, payment_submitted,
 *                             payment_approved, payment_rejected, receipt_issued.
 * @param int|null    $studentId
 */
function send_mail(string $to, string $subject, string $htmlBody, string $type, ?int $studentId = null): bool
{
    $sent = false;
    $vendorAutoload = BASE_PATH . '/vendor/autoload.php';

    try {
        if (is_readable($vendorAutoload)) {
            require_once $vendorAutoload;
        }

        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class) && SMTP_HOST !== '') {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = SMTP_HOST;
            $mailer->Port = SMTP_PORT;
            $mailer->SMTPAuth = true;
            $mailer->Username = SMTP_USER;
            $mailer->Password = SMTP_PASS;
            $mailer->SMTPSecure = SMTP_ENCRYPTION === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mailer->addAddress($to);
            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $htmlBody;
            $mailer->AltBody = trim(strip_tags($htmlBody));
            $mailer->send();
            $sent = true;
        } else {
            // Fallback for local development without SMTP configured.
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= 'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . ">\r\n";
            $sent = @mail($to, $subject, $htmlBody, $headers);
        }
    } catch (\Throwable $e) {
        error_log('NOTE BANK MAIL ERROR: ' . $e->getMessage());
        $sent = false;
    }

    try {
        $stmt = get_db()->prepare(
            'INSERT INTO email_logs (student_id, email, subject, type, status, sent_at)
             VALUES (:student_id, :email, :subject, :type, :status, NOW())'
        );
        $stmt->execute([
            'student_id' => $studentId,
            'email' => $to,
            'subject' => $subject,
            'type' => $type,
            'status' => $sent ? 'SENT' : 'FAILED',
        ]);
    } catch (\Throwable $e) {
        error_log('NOTE BANK EMAIL LOG ERROR: ' . $e->getMessage());
    }

    return $sent;
}
