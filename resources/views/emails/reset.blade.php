<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reset Your Password</title>
</head>
<body style="margin:0;padding:0;background:#0a0f0c;font-family:'Helvetica Neue',Arial,sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0f0c;padding:40px 16px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">

                    {{-- Logo --}}
                    <tr>
                        <td align="center" style="padding-bottom:32px;">
                            <table cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:linear-gradient(135deg,#C8873A,#E8A850);border-radius:10px;width:36px;height:36px;text-align:center;vertical-align:middle;">
                                        <span style="color:#0D1F1A;font-weight:900;font-size:16px;line-height:36px;">
                                            {{ strtoupper(substr(env('APP_NAME', 'R'), 0, 1)) }}
                                        </span>
                                    </td>
                                    <td style="padding-left:10px;vertical-align:middle;">
                                        <span style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:-0.3px;">
                                            {{ env('APP_NAME', 'REU.ng') }}
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td style="background:#0D1F1A;border-radius:16px;border:1px solid rgba(255,255,255,0.08);padding:40px 36px;">

                            {{-- Icon --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" style="padding-bottom:24px;">
                                        <div style="width:52px;height:52px;background:rgba(200,135,58,0.12);border:1px solid rgba(200,135,58,0.25);border-radius:12px;font-size:22px;line-height:52px;text-align:center;">
                                            🔑
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <h1 style="margin:0 0 8px;color:#ffffff;font-size:22px;font-weight:700;text-align:center;letter-spacing:-0.3px;">
                                Password Reset
                            </h1>
                            <p style="margin:0 0 28px;color:rgba(255,255,255,0.45);font-size:14px;text-align:center;line-height:1.6;">
                                Hi {{ $name }}, use the code below to reset your password.
                            </p>

                            {{-- Code block --}}
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                                <tr>
                                    <td style="background:rgba(200,135,58,0.08);border:1px solid rgba(200,135,58,0.2);border-radius:12px;padding:24px;text-align:center;">
                                        <p style="margin:0 0 6px;color:rgba(200,135,58,0.6);font-size:10px;font-weight:700;letter-spacing:0.2em;text-transform:uppercase;">
                                            Reset Code
                                        </p>
                                        <p style="margin:0;color:#E8A850;font-size:36px;font-weight:800;letter-spacing:0.18em;font-family:'Courier New',monospace;">
                                            {{ $verificationCode }}
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            {{-- Expiry notice --}}
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                                <tr>
                                    <td style="background:rgba(251,191,36,0.06);border:1px solid rgba(251,191,36,0.12);border-radius:10px;padding:14px 16px;">
                                        <p style="margin:0;color:rgba(251,191,36,0.7);font-size:13px;text-align:center;line-height:1.5;">
                                            This code expires in <strong style="color:#fbbf24;">10 minutes</strong>.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0;color:rgba(255,255,255,0.35);font-size:13px;text-align:center;line-height:1.6;">
                                If you did not request a password reset, please ignore this email or contact support.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding-top:24px;text-align:center;">
                            <p style="margin:0 0 6px;color:rgba(255,255,255,0.2);font-size:12px;">
                                Need help? Email us at
                                 <a href="mailto:{{ config('mail.to.address', 'support@reu.ng') }}"
                                   style="color:rgba(200,135,58,0.7);text-decoration:none;">
                                    {{ config('mail.to.address', 'support@reu.ng') }}
                                </a>
                            </p>
                            <p style="margin:0;color:rgba(255,255,255,0.12);font-size:11px;">
                                &copy; {{ date('Y') }} {{ config('app.name', 'REU.ng') }}. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>
</html>