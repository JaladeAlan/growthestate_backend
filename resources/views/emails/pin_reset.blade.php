@component('mail::message')
# Hello {{ $userName }},

You recently requested to reset your **Transaction PIN**.

Your verification code is:

@component('mail::panel')
## 🔢 {{ $code }}
@endcomponent

This code will expire in **10 minutes**, so please complete the reset process promptly.

If you did not request this change, you can safely ignore this email.

Thanks,  
**The GrowthEstate Team**

@component('mail::subcopy')
Need help? Contact our support team anytime at [support@growthestate.com](mailto:support@growthestate.com).
@endcomponent
@endcomponent
