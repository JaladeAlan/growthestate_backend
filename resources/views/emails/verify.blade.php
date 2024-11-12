<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification</title>
</head>
<body>
    <h1>Welcome to Growth Estate</h1>
    <p>Hi {{ $name }},</p>
    <p>Thank you for registering. Please use the following verification code to verify your email address:</p>
    <h2>{{ $verificationCode }}</h2>
    <p>This code will expire in 15 minutes.</p>
    <p>If you did not register, please ignore this email.</p>
</body>
</html>
