<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * includes/email.php
 *
 * PHP's built-in mail() almost never works from a XAMPP localhost, so
 * this uses PHPMailer + SMTP instead — the realistic path for a
 * student project. It degrades gracefully: if PHPMailer isn't
 * installed yet, alerts are just logged instead of crashing the page
 * that triggered them (e.g. approving a tenant shouldn't fail just
 * because email isn't set up yet).
 *
 * To enable real sending:
 *   1. composer require phpmailer/phpmailer
 *      (no Composer? download the PHPMailer "src" folder from
 *       https://github.com/PHPMailer/PHPMailer and require its 3 files
 *       instead of vendor/autoload.php below)
 *   2. Fill in SMTP_USERNAME / SMTP_PASSWORD in config/mail.local.php with a Gmail
 *      "App Password" (Google Account -> Security -> App passwords),
 *      NOT your normal Gmail password.
 */

// Mail credentials are NOT stored in this file. Copy
// config/mail.local.example.php to config/mail.local.php and fill it in.
// (mail.local.php is git-ignored so the password never gets shared.)
$mailLocal = __DIR__ . '/../config/mail.local.php';
if (is_file($mailLocal)) {
    require_once $mailLocal;
}
defined('SMTP_HOST')         || define('SMTP_HOST', 'smtp.gmail.com');
defined('SMTP_PORT')         || define('SMTP_PORT', 587);
defined('SMTP_USERNAME')     || define('SMTP_USERNAME', '');
defined('SMTP_PASSWORD')     || define('SMTP_PASSWORD', '');
// Where "Report an Issue" submissions are emailed (defaults to the sender account).
defined('ISSUE_REPORT_EMAIL') || define('ISSUE_REPORT_EMAIL', SMTP_USERNAME);

/**
 * Sends an HTML email. Returns true if actually sent, false if it was
 * only logged (PHPMailer missing) or failed.
 */
function send_email_alert(string $toEmail, string $toName, string $subject, string $bodyHtml, ?string $attachmentPath = null, ?string $attachmentName = null): bool
{
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (SMTP_USERNAME === '' || SMTP_PASSWORD === '') {
        error_log("[email not sent — config/mail.local.php missing or empty] To: {$toEmail} | Subject: {$subject}");
        return false;
    }

    if (!file_exists($autoload)) {
        error_log("[email not sent — PHPMailer not installed] To: {$toEmail} <{$toName}> | Subject: {$subject}");
        return false;
    }

    require_once $autoload;

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_USERNAME, defined('SITE_NAME') ? SITE_NAME : 'Dorm Tenant Management System');
        $mail->addAddress($toEmail, $toName);
        if ($attachmentPath !== null && is_file($attachmentPath)) {
          $mail->addAttachment($attachmentPath, $attachmentName ?? basename($attachmentPath));
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = strip_tags($bodyHtml);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Email failed for ' . $toEmail . ': ' . $mail->ErrorInfo);
        return false;
    }
}

/** Wraps a short message in a minimal branded HTML shell. */
function email_template(string $heading, string $message): string
{
    $site = htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'Dorm Tenant Management System');
    return "
    <div style='font-family:Arial,sans-serif;max-width:480px;margin:0 auto'>
      <div style='background:#800000;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0'>
        <strong>{$site}</strong>
      </div>
      <div style='border:1px solid #e9ecef;border-top:none;padding:20px;border-radius:0 0 8px 8px'>
        <h2 style='margin-top:0;color:#212529;font-size:18px'>" . htmlspecialchars($heading) . "</h2>
        <p style='color:#495057;line-height:1.6'>" . nl2br(htmlspecialchars($message)) . "</p>
      </div>
    </div>";
}
