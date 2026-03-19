<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Verify Your Email</title>
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
                                            {{ strtoupper(substr(env('APP_NAME', 'S'), 0, 1)) }}
                                        </span>
                                    </td>
                                    <td style="padding-left:10px;vertical-align:middle;">
                                        <span style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:-0.3px;">
                                            {{ env('APP_NAME', 'Sproutvest') }}
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
                                        <div style="width:52px;height:52px;background:rgba(45,122,85,0.15);border:1px solid rgba(45,122,85,0.3);border-radius:12px;font-size:22px;line-height:52px;text-align:center;">
                                            ✉️
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <h1 style="margin:0 0 8px;color:#ffffff;font-size:22px;font-weight:700;text-align:center;letter-spacing:-0.3px;">
                                Verify Your Email
                            </h1>
                            <p style="margin:0 0 28px;color:rgba(255,255,255,0.45);font-size:14px;text-align:center;line-height:1.6;">
                                Welcome to {{ env('APP_NAME', 'Sproutvest') }}, {{ $name }}! Use the code below to verify your email address and activate your account.
                            </p>

                            {{-- Code block --}}
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                                <tr>
                                    <td style="background:rgba(45,122,85,0.08);border:1px solid rgba(45,122,85,0.2);border-radius:12px;padding:24px;text-align:center;">
                                        <p style="margin:0 0 6px;color:rgba(74,222,128,0.5);font-size:10px;font-weight:700;letter-spacing:0.2em;text-transform:uppercase;">
                                            Verification Code
                                        </p>
                                        <p style="margin:0;color:#4ade80;font-size:36px;font-weight:800;letter-spacing:0.18em;font-family:'Courier New',monospace;">
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
                                            This code expires in <strong style="color:#fbbf24;">15 minutes</strong>.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0;color:rgba(255,255,255,0.35);font-size:13px;text-align:center;line-height:1.6;">
                                If you did not create an account, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding-top:24px;text-align:center;">
                            <p style="margin:0 0 6px;color:rgba(255,255,255,0.2);font-size:12px;">
                                Need help? Email us at
                                <a href="mailto:{{ env('MAIL_FROM_ADDRESS', 'hello@sproutvest.com') }}"
                                   style="color:rgba(200,135,58,0.7);text-decoration:none;">
                                    {{ env('MAIL_FROM_ADDRESS', 'hello@sproutvest.com') }}
                                </a>
                            </p>
                            <p style="margin:0;color:rgba(255,255,255,0.12);font-size:11px;">
                                &copy; {{ date('Y') }} {{ env('APP_NAME', 'Sproutvest') }}. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>
</html>