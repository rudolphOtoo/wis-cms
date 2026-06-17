@component('mail::message')
# Welcome to {{ $churchName }}!

Hello {{ $member->first_name }},

Thank you for joining our community! We're thrilled to have you as part of our church family.

As a new member, you'll have access to:
- 📅 Service schedules and updates
- 👥 Connect with other members in your cell group  
- 📢 Important announcements and prayer requests
- 🙏 Community events and fellowship opportunities

**Next Steps:**
1. Keep an eye on your phone for messages from us
2. Look out for an invitation to join your cell group
3. Feel free to reach out with any questions

We believe God has an amazing purpose for you here, and we can't wait to see how He works through you!

In Christ,  
{{ $churchName }} Team

@component('mail::subcopy')
If you have any questions or concerns, please don't hesitate to reach out to us.
@endcomponent
@endcomponent
