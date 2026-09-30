<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Password Reset</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; background-color: #f4f7fb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        .email-wrapper { width: 100%; background-color: #f4f7fb; padding: 35px 15px; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06); border: 1px solid #e9edf4; }
        .header-banner { background: linear-gradient(135deg, #b91c1c 0%, #ef4444 100%); padding: 36px 30px; text-align: center; color: #ffffff; }
        .header-badge { display: inline-block; background: rgba(255, 255, 255, 0.2); padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 12px; }
        .header-title { margin: 0; font-size: 24px; font-weight: 700; color: #ffffff; line-height: 1.3; }
        .header-subtitle { margin: 8px 0 0; font-size: 14px; color: #fee2e2; font-weight: 400; }
        .content-body { padding: 32px 30px; color: #334155; }
        .greeting { font-size: 18px; font-weight: 600; color: #1e293b; margin-bottom: 12px; }
        .message-text { font-size: 15px; line-height: 1.6; color: #475569; margin-bottom: 24px; }
        .otp-container { background: #fef2f2; border: 2px dashed #fca5a5; border-radius: 10px; padding: 24px; text-align: center; margin: 25px 0; }
        .otp-label { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #991b1b; font-weight: 600; margin-bottom: 8px; }
        .otp-code { font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #b91c1c; font-family: 'Courier New', Courier, monospace; }
        .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 22px 30px; text-align: center; font-size: 12px; color: #94a3b8; }
        .footer p { margin: 4px 0; }
    </style>
</head>
<body>
    <table role="presentation" class="email-wrapper" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" class="email-container" width="100%" cellspacing="0" cellpadding="0" border="0">
                    <!-- Header -->
                    <tr>
                        <td class="header-banner">
                            <div class="header-badge">🔑 Security</div>
                            <h1 class="header-title">Reset Your Password</h1>
                            <p class="header-subtitle">We received a request to reset your password</p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td class="content-body">
                            <div class="greeting">Hello {{ $name }},</div>
                            <p class="message-text">
                                You requested to reset your password. Use the verification code below to verify your request and set a new password:
                            </p>

                            <div class="otp-container">
                                <div class="otp-label">Password Reset Code</div>
                                <div class="otp-code">{{ $code }}</div>
                            </div>

                            <p class="message-text" style="font-size: 13px; color: #64748b;">
                                If you did not request a password reset, please ignore this email or secure your account.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td class="footer">
                            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
                            <p>This is an automated message, please do not reply directly to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
