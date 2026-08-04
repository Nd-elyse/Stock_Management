<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Generate a random numeric OTP code.
 */
function generate_otp_code(int $length = 6): string
{
    $min = (int) str_pad('1', $length, '0');
    $max = (int) str_pad('', $length, '9');
    return (string) random_int($min, $max);
}

/**
 * Send an OTP code to the given email address.
 *
 * @return array{success: bool, message: string}
 */
function send_otp_email(string $toEmail, string $toName, string $otp): array
{
    $config = require __DIR__ . '/../config/mailer.php';

    if (empty($config['username']) || empty($config['password'])) {
        error_log('SMTP is not configured: set SMTP_USERNAME and SMTP_PASSWORD environment variables.');
        return ['success' => false, 'message' => 'Email delivery is not configured. Please contact support.'];
    }

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->SMTPSecure = $config['encryption'] === 'tls'
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $config['port'];

        if (!empty($config['smtp_skip_cert_verify'])) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        // Recipients
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your GarageManager verification code';
        $mail->Body    = '
            <div style="font-family:Arial,sans-serif;max-width:420px;margin:auto;">
                <h2 style="color:#1d4ed8;">GarageManager</h2>
                <p>Hello ' . htmlspecialchars($toName) . ',</p>
                <p>Your one-time verification code is:</p>
                <p style="font-size:28px;font-weight:bold;letter-spacing:6px;text-align:center;
                          background:#f1f5f9;padding:14px;border-radius:10px;">' . htmlspecialchars($otp) . '</p>
                <p>This code expires in 5 minutes. If you did not request this, you can safely ignore this email.</p>
            </div>';
        $mail->AltBody = "Your GarageManager verification code is: {$otp}. It expires in 5 minutes.";

        $mail->send();
        return ['success' => true, 'message' => 'OTP sent.'];
    } catch (PHPMailerException $e) {
        return ['success' => false, 'message' => 'Could not send verification email: ' . $mail->ErrorInfo];
    }
}
