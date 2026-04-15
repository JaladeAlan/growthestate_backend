<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Deposit Confirmed</title>
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
                                            {{ strtoupper(substr(config('app.name', 'R'), 0, 1)) }}
                                        </span>
                                    </td>
                                    <td style="padding-left:10px;vertical-align:middle;">
                                        <span style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:-0.3px;">
                                            {{ config('app.name', 'REU.ng') }}
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
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="width:52px;height:52px;background:rgba(59,180,118,0.10);border:1px solid rgba(59,180,118,0.25);border-radius:12px;text-align:center;vertical-align:middle;font-size:22px;">
                                                    💰
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <h1 style="margin:0 0 8px;color:#ffffff;font-size:22px;font-weight:700;text-align:center;letter-spacing:-0.3px;">
                                Deposit Confirmed
                            </h1>
                            <p style="margin:0 0 28px;color:rgba(255,255,255,0.45);font-size:14px;text-align:center;line-height:1.6;">
                                Hi {{ $notifiable->name }}, your deposit has been credited to your wallet.
                            </p>

                            {{-- Amount highlight --}}
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">
                                <tr>
                                    <td style="background:rgba(59,180,118,0.08);border:1px solid rgba(59,180,118,0.2);border-radius:12px;padding:20px;text-align:center;">
                                        <p style="margin:0 0 4px;color:rgba(59,180,118,0.6);font-size:10px;font-weight:700;letter-spacing:0.2em;text-transform:uppercase;">
                                            Amount Deposited
                                        </p>
                                        <p style="margin:0;color:#3BB476;font-size:28px;font-weight:800;letter-spacing:-0.5px;">
                                            &#x20A6;{{ number_format($amountKobo / 100, 2) }}
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            {{-- Deposit details --}}
                            @if($reference)
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                                <tr>
                                    <td style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:20px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:5px 0;border-bottom:1px solid rgba(255,255,255,0.06);">
                                                    <table width="100%" cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            <td style="color:rgba(255,255,255,0.4);font-size:12px;text-align:left;">Date</td>
                                                            <td style="color:#ffffff;font-size:13px;font-weight:500;text-align:right;">{{ $date }}</td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:5px 0;">
                                                    <table width="100%" cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            <td style="color:rgba(255,255,255,0.4);font-size:12px;text-align:left;">Reference</td>
                                                            <td style="color:rgba(200,135,58,0.85);font-size:12px;font-weight:600;text-align:right;font-family:monospace;">{{ $reference }}</td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            @else
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                                <tr>
                                    <td style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:20px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:5px 0;">
                                                    <table width="100%" cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            <td style="color:rgba(255,255,255,0.4);font-size:12px;text-align:left;">Date</td>
                                                            <td style="color:#ffffff;font-size:13px;font-weight:500;text-align:right;">{{ $date }}</td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            @endif

                            {{-- CTA --}}
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ rtrim(config('app.frontend_url', '#'), '/') }}/wallet"
                                           style="display:inline-block;background:linear-gradient(135deg,#C8873A,#E8A850);color:#0D1F1A;font-size:14px;font-weight:700;text-decoration:none;padding:13px 32px;border-radius:8px;letter-spacing:0.01em;">
                                            View Wallet
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0;color:rgba(255,255,255,0.35);font-size:13px;text-align:center;line-height:1.6;">
                                If you did not authorize this deposit, please contact our support team immediately.
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