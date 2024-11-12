<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
</head>
<body>
    <h1>Password Reset Request</h1>
    <p>Hi {{ $name }},</p>
    <p>We received a request to reset the password for your account. Please use the following code to complete your password reset:</p>
    <h2>{{ $verificationCode }}</h2>
    <p>This code will expire in 30 minutes.</p>
    <p>If you did not request a password reset, please ignore this email or contact support for assistance.</p>
    <p>Thank you,<br>Your Support Team</p>
</body>
</html>
