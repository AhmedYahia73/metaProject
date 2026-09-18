New Contact Us Message Received

From: {{ $fullName }}
Email: {{ $data['email'] }}
Phone: {{ $data['phone'] }}

Message:
{{ $data['message'] }}

---
This email was sent from the contact form on {{ config('app.name') }}.
Reply directly to this email to contact {{ $fullName }}.
