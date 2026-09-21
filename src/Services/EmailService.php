<?php

declare(strict_types=1);

namespace SimpleKuma\Services;

use mysqli;

/**
 * Email Service
 * Handles sending emails with PHPMailer (preferred) or PHP mail() fallback
 * Works out of the box on most PHP servers with automatic configuration
 */
class EmailService
{
    private mysqli $db;
    private array $config;
    private const RESET_TOKEN_LIFETIME = 3600; // 1 hour

    public function __construct(mysqli $db, array $config = [])
    {
        $this->db = $db;
        
        // Auto-detect domain from BASE_URL for from_email
        $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost';
        $domain = $this->extractDomainFromUrl($baseUrl);
        $defaultFromEmail = 'noreply@' . $domain;
        
        $this->config = array_merge([
            'enabled' => true, // Enabled by default - will use PHP mail() if SMTP not configured
            'use_smtp' => false, // Set to true to force SMTP (requires SMTP credentials)
            'from_email' => $defaultFromEmail,
            'from_name' => 'Simple KUMA',
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls', // tls or ssl
            'smtp_auth' => true,
            'development_mode' => defined('APP_ENV') && APP_ENV !== 'production',
        ], $config);
    }

    /**
     * Extract domain from URL
     */
    private function extractDomainFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (isset($parsed['host'])) {
            return $parsed['host'];
        }
        
        // Fallback: try to extract from URL string
        if (preg_match('/https?:\/\/([^\/]+)/', $url, $matches)) {
            return $matches[1];
        }
        
        // Final fallback
        return 'localhost';
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail(string $email, string $token, string $resetUrl): bool
    {
        $subject = 'Reset Your Simple KUMA Password';
        $body = $this->getPasswordResetEmailTemplate($resetUrl, $token);

        // In development mode, log the email instead of sending
        if ($this->config['development_mode'] && !$this->config['enabled']) {
            error_log("=== PASSWORD RESET EMAIL (Development Mode) ===");
            error_log("To: {$email}");
            error_log("Subject: {$subject}");
            error_log("Reset URL: {$resetUrl}");
            error_log("Token: {$token}");
            error_log("===============================================");
            return true; // Return true so flow continues
        }

        // Try PHPMailer first (if available)
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            return $this->sendViaPHPMailer($email, $subject, $body);
        }

        // Fallback to PHP mail() function
        return $this->sendViaPHPMail($email, $subject, $body);
    }

    /**
     * Send email via PHPMailer (preferred method)
     */
    private function sendViaPHPMailer(string $to, string $subject, string $body): bool
    {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            if ($this->config['use_smtp'] && !empty($this->config['smtp_host'])) {
                // SMTP configuration
                $mail->isSMTP();
                $mail->Host = $this->config['smtp_host'];
                $mail->SMTPAuth = $this->config['smtp_auth'];
                $mail->Username = $this->config['smtp_username'];
                $mail->Password = $this->config['smtp_password'];
                $mail->SMTPSecure = $this->config['smtp_encryption'];
                $mail->Port = $this->config['smtp_port'];
                
                // Enable verbose debug output (only in development)
                if ($this->config['development_mode']) {
                    $mail->SMTPDebug = 2;
                    $mail->Debugoutput = function($str, $level) {
                        error_log("PHPMailer: $str");
                    };
                }
            } else {
                // Use PHP mail() function via PHPMailer (better headers and encoding)
                $mail->isMail();
            }
            
            // Recipients
            $mail->setFrom($this->config['from_email'], $this->config['from_name']);
            $mail->addAddress($to);
            $mail->addReplyTo($this->config['from_email'], $this->config['from_name']);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);
            
            // Character encoding
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            
            $mail->send();
            return true;
            
        } catch (\Exception $e) {
            error_log("PHPMailer Error: " . $e->getMessage());
            
            // Fallback to PHP mail() if PHPMailer fails
            return $this->sendViaPHPMail($to, $subject, $body);
        }
    }

    /**
     * Send email via PHP mail() function (fallback)
     * Works on most cPanel/shared hosting servers without configuration
     */
    private function sendViaPHPMail(string $to, string $subject, string $body): bool
    {
        // Ensure from email is valid for the domain
        $fromEmail = $this->config['from_email'];
        $domain = $this->extractDomainFromUrl(defined('BASE_URL') ? BASE_URL : 'http://localhost');
        
        // If from email doesn't match domain, use domain-based email
        if (substr($fromEmail, -strlen('@' . $domain)) !== '@' . $domain) {
            $fromEmail = 'noreply@' . $domain;
        }
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->config['from_name'] . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'X-Mailer: Simple KUMA',
            'X-Priority: 3',
        ];

        // Use proper line endings
        $headersString = implode("\r\n", $headers);
        
        // Encode subject if it contains non-ASCII characters
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        
        return @mail($to, $encodedSubject, $body, $headersString);
    }

    /**
     * Get password reset email template
     */
    private function getPasswordResetEmailTemplate(string $resetUrl, string $token): string
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; background: #f5f1e8;">
    <div style="background: #fff; border-radius: 8px; padding: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #3d5a26; margin: 0;">Simple KUMA</h1>
        </div>
        
        <h2 style="color: #3d5a26; margin-top: 0;">Reset Your Password</h2>
        
        <p>You requested to reset your password. Click the button below to continue:</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="' . htmlspecialchars($resetUrl) . '" style="display: inline-block; padding: 14px 28px; background: #3d5a26; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">Reset Password</a>
        </div>
        
        <p style="color: #666; font-size: 14px;">Or copy and paste this link into your browser:</p>
        <p style="word-break: break-all; color: #666; font-size: 12px; background: #f8f9fa; padding: 10px; border-radius: 4px;">' . htmlspecialchars($resetUrl) . '</p>
        
        <p style="color: #999; font-size: 12px; margin-top: 30px;">This link will expire in 1 hour. If you didn\'t request a password reset, please ignore this email.</p>
        
        <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">
        
        <p style="color: #999; font-size: 12px; text-align: center; margin: 0;">Simple KUMA - Affiliate Tracker</p>
    </div>
</body>
</html>';

        return $html;
    }
}

