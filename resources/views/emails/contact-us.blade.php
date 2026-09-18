<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>New Contact Message</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; background-color: #f4f7fb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        .email-wrapper { width: 100%; background-color: #f4f7fb; padding: 35px 15px; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06); border: 1px solid #e9edf4; }
        .header-banner { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 36px 30px; text-align: center; color: #ffffff; }
        .header-badge { display: inline-block; background: rgba(255, 255, 255, 0.2); padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 12px; }
        .header-title { margin: 0; font-size: 24px; font-weight: 700; color: #ffffff; line-height: 1.3; }
        .header-subtitle { margin: 8px 0 0; font-size: 14px; color: #dbeafe; font-weight: 400; }
        .content-body { padding: 32px 30px; color: #334155; }
        .info-card { background-color: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; padding: 18px 20px; margin-bottom: 25px; }
        .info-table { width: 100%; border-collapse: collapse; }
        .info-table td { padding: 8px 6px; font-size: 14px; vertical-align: middle; }
        .info-label { color: #64748b; font-weight: 600; width: 35%; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        .info-value { color: #0f172a; font-weight: 500; }
        .info-value a { color: #2563eb; text-decoration: none; font-weight: 600; }
        .message-section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #475569; margin: 0 0 10px 0; }
        .message-box { background-color: #f1f5f9; border-left: 4px solid #3b82f6; border-radius: 0 8px 8px 0; padding: 20px; color: #1e293b; font-size: 15px; line-height: 1.6; white-space: pre-wrap; word-wrap: break-word; }
        .action-buttons { margin-top: 28px; text-align: center; }
        .btn-reply { display: inline-block; background-color: #2563eb; color: #ffffff !important; padding: 12px 24px; font-size: 14px; font-weight: 600; border-radius: 6px; text-decoration: none; box-shadow: 0 2px 6px rgba(37, 99, 235, 0.35); }
        .btn-call { display: inline-block; background-color: #059669; color: #ffffff !important; padding: 12px 24px; font-size: 14px; font-weight: 600; border-radius: 6px; text-decoration: none; margin-left: 10px; box-shadow: 0 2px 6px rgba(5, 150, 105, 0.35); }
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
                            <div class="header-badge">📩 Contact Form Submission</div>
                            <h1 class="header-title">New Client Inquiry</h1>
                            <p class="header-subtitle">You have received a new message from the contact form</p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td class="content-body">
                            <!-- Sender Information Table -->
                            <div class="info-card">
                                <table class="info-table" role="presentation" cellspacing="0" cellpadding="0">
                                    <tr>
                                        <td class="info-label">👤 Full Name</td>
                                        <td class="info-value">{{ $fullName }}</td>
                                    </tr>
                                    <tr>
                                        <td class="info-label">✉️ Email</td>
                                        <td class="info-value">
                                            <a href="mailto:{{ $data['email'] }}">{{ $data['email'] }}</a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="info-label">📞 Phone</td>
                                        <td class="info-value">
                                            <a href="tel:{{ $data['phone'] }}">{{ $data['phone'] }}</a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="info-label">🕒 Submitted At</td>
                                        <td class="info-value">{{ now()->toDayDateTimeString() }}</td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Message Content -->
                            <h3 class="message-section-title">💬 Message Details</h3>
                            <div class="message-box">
                                {!! nl2br(e($data['message'])) !!}
                            </div>

                            <!-- Action Buttons -->
                            <div class="action-buttons">
                                <a href="mailto:{{ $data['email'] }}?subject=Re:%20Inquiry%20from%20{{ urlencode($fullName) }}" class="btn-reply">
                                    ↩️ Reply via Email
                                </a>
                                @if(!empty($data['phone']))
                                <a href="tel:{{ $data['phone'] }}" class="btn-call">
                                    📞 Call Sender
                                </a>
                                @endif
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td class="footer">
                            <p>This automated message was sent from <strong>{{ config('app.name', 'Meta Restaurant Platform') }}</strong>.</p>
                            <p>Recipient: <code>{{ config('mail.my_email', env('My_Email')) }}</code></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
