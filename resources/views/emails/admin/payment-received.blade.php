<x-mail::message>
    # New Payment Notification

    A new payment has been successfully processed on {{ $appName }}.

    Payment Details:
    - Payment ID (Stripe): `{{ $payment->stripe_payment_intent_id }}`
    - Amount: ${{ $payment->amount }} {{ strtoupper($payment->currency) }}
    - Date: {{ $payment->paid_at->toFormattedDayDateString() }} at {{ $payment->paid_at->toTimeString() }}


    User Details:
    - User Name: {{ $user->first_name }} {{ $user->last_name }}
    - User Email: `{{ $user->email }}`

    User Org. Access Code: `{{ $payment->user_organization_access_code }}`

    The user's subscription access has been activated or extended.

    Thanks,
    {{ $appName }} System
</x-mail::message>
