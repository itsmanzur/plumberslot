=== TutorSlot ===
Contributors: tutorslot
Tags: booking, tutor, appointment, lessons, scheduling
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scheduling for tutors with weekly availability, recurring lessons, parent
accounts, lesson packages, optional payments and online meetings.

== Description ==

Most booking plugins were built for salons and grew a tutoring page later. It
shows: they call your subject a "Service", your student a "Customer", and they
have no idea that the person paying and the person attending are usually two
different people.

TutorSlot starts from how teaching actually works.

* **Visual weekly availability** — paint 30-minute cells and add one-off closed
  dates without rebuilding the whole timetable.
* **Tutor-specific subjects** — set level, curriculum, duration, price and an
  optional trial subject.
* **Public booking flow** — publish it with the TutorSlot block or the
  `[tutorslot]` shortcode. Visitors can browse tutors and open times; a
  WordPress account is required to confirm a booking.
* **Single and recurring lessons** — book one lesson or choose multiple
  weekdays and a course length for a weekly series. Individual lessons can be
  moved without moving the remaining series.
* **Parent and student accounts** — a parent links existing student accounts,
  books for a child and sees family lessons from the parent dashboard.
* **Lesson packages** — issue tutor/subject credits, spend them atomically with
  a booking and track balances, expiry, refunds and rollover policy. Package
  issuance is separate from online gateway checkout in this release.
* **Booking lifecycle tools** — tutors can manage bookings, reschedule or
  cancel, record attendance, save lesson notes and export booking CSV data.
* **Optional payments** — Stripe and bKash are built in. Sites can also allow
  direct payment to the tutor or package credits; WooCommerce is not required.
* **Optional online meetings** — connect Google Meet or configure Zoom.
  Participant join links are signed and time-limited.
* **Notifications and privacy** — scheduled 24-hour and 1-hour email reminders,
  parent copies, WordPress personal-data export/erasure and suggested privacy
  policy text. SMS delivery is available through a developer hook and requires
  a separate provider integration.
* **Role-aware REST API** — public availability is intentionally readable;
  protected booking, family, tutor, payment and administration operations use
  nonce, capability and ownership checks.
* **Interactive Help & Docs** — a plain-English in-product guide introduces
  TutorSlot, explains its tutor-first differences, saves four-step setup
  progress, searches feature walkthroughs and links to an externally hosted
  quick-tour video while keeping a written outline in the plugin.

= Security =

Booking plugins hold minors' names, schedules and guardians' contact details.
TutorSlot treats that as the sensitive data it is:

* Public REST access is limited to tutor profiles, subjects and open slots.
  Protected reads are account-scoped; state-changing routes validate a REST
  nonce plus the applicable capability and ownership rules.
* Dynamic SQL values are prepared and table identifiers come from an internal
  schema whitelist.
* Rendered PHP output is escaped, and the Preact interfaces render remote data
  as text rather than HTML.
* Meeting emails use signed, expiring TutorSlot URLs instead of raw provider
  meeting URLs.
* Configured API secrets are encrypted at rest and masked in API responses.
* Sensitive booking, payment, meeting, settings and privacy actions are written
  to an audit log.
* Webhooks are signature-checked and stored for replay protection.
* Coding standards, static analysis and a dependency audit run on every commit.

Report vulnerabilities privately to security@tutorslot.com. Do not publish
exploit details in a public issue. The full reporting and responsible-testing
policy is at https://github.com/itsmanzur/tutorslot/blob/main/SECURITY.md.

== Installation ==

1. Install and activate.
2. The setup wizard opens automatically — four steps, about ninety seconds.
3. Add the TutorSlot booking block to a page, or paste
   `[tutorslot tutor="your-name"]` into a shortcode block.
4. Configure Stripe, bKash, Google Meet or Zoom under TutorSlot settings only
   when those optional services are needed.
5. Customers sign in to confirm lessons and use student or parent dashboards.

== External services ==

TutorSlot can schedule lessons without contacting any payment or meeting
provider. The following optional services are contacted only after a site
administrator configures them and a user invokes the related feature. The site
owner is responsible for providing any notices or obtaining any consent required
for its use of these services.

= Stripe =

When a customer chooses card payment, TutorSlot creates a Stripe-hosted Checkout
session. It sends the site's Stripe credential, booking identifier, amount,
currency, a generic "Lesson" item name and success/cancel URLs. Refund requests
send the payment reference and refund amount. Card details are entered on
Stripe's hosted page and do not pass through or get stored by TutorSlot. Stripe
also sends signed payment-status webhooks back to the site.

Privacy policy: https://stripe.com/privacy
Services agreement: https://stripe.com/legal/ssa

= bKash =

When a customer chooses bKash, TutorSlot authenticates with the configured
merchant credentials and sends a booking-derived payer reference and invoice
number, amount in BDT and a callback URL to bKash Tokenized Checkout. Completing,
checking or refunding a payment sends its bKash payment/transaction reference;
refunds also include the amount and a cancellation reason. Account, OTP and PIN
details are handled on bKash's pages and are not stored by TutorSlot.

Privacy notice: https://www.bkash.com/en/page/privacy-notice
Payment gateway terms: https://www.bkash.com/en/page/tokenized_checkout

= Google Calendar and Google Meet =

When a tutor chooses to connect Google Meet, TutorSlot uses Google OAuth with the
`calendar.events` permission. OAuth exchanges send the configured client
credentials, authorization or refresh token and this site's callback URL. For a
confirmed lesson, TutorSlot sends the student's WordPress display name in the
event title, lesson start/end time and a booking-derived conference request
identifier to Google Calendar. It later sends the event identifier when resolving
the Meet join URL. TutorSlot does not add student or parent email addresses as
Google Calendar attendees.

Privacy policy: https://policies.google.com/privacy
Terms of service: https://policies.google.com/terms

= Zoom =

When Zoom is configured, TutorSlot exchanges the site's server-to-server OAuth
account/client credentials for an access token. It sends the student's WordPress
display name in the meeting topic, lesson start time and duration, and secure
meeting settings to create a meeting. The returned meeting identifier is sent
again when resolving a join URL or deleting a cancelled meeting. TutorSlot sets
automatic recording to "none".

Privacy statement: https://www.zoom.com/en/trust/privacy/privacy-statement/
Terms of service: https://www.zoom.com/en/trust/terms/

= Email and SMS delivery =

Email is sent through the site's standard WordPress `wp_mail` configuration;
TutorSlot does not bundle an external email delivery service. TutorSlot also does
not contact an SMS provider itself. If the site enables SMS and installs code that
handles the `tutorslot_send_sms` action, that code receives the recipient's mobile
number, the reminder or cancellation message and the booking record. The site
owner must document the selected email/SMS provider and its data practices.

== Screenshots ==

1. Students choose a tutor-specific subject in the responsive public booking widget.
2. Tutors paint weekly open hours and manage lesson defaults from one timetable.
3. Students review the held time, lesson details and learner before confirming.
4. The completion screen presents the booking summary, calendar action and dashboard link.
5. Parents see family lessons, lesson credits and tutor progress notes in one dashboard.
6. The public subject picker stays touch-friendly and readable on a 390px mobile viewport.

== Frequently Asked Questions ==

= Does it work with Tutor LMS or LearnDash? =

There is no direct Tutor LMS or LearnDash bridge in this release. You can place
the TutorSlot block or shortcode on a course-related WordPress page and link to
it from the LMS. A dedicated instructor bridge remains a future integration.

= Do I need WooCommerce? =

No. Payments are optional entirely, and when you do turn them on you can use
Stripe or bKash directly without WooCommerce. You can also accept payment to the
tutor outside the site or use lesson-package credits.

= Can visitors book without an account? =

Visitors can view tutor details, subjects and open times. They must sign in to
hold a time and confirm a single or recurring booking. This lets TutorSlot apply
ownership checks and connect lessons to student and parent dashboards.

= Which meeting providers are included? =

Google Meet uses a tutor OAuth connection and Google Calendar. Zoom uses a
server-to-server OAuth application configured by the site. Meetings are
optional; bookings also work without a connected meeting provider.

= Does TutorSlot send SMS messages itself? =

Email notifications are included. TutorSlot exposes the `tutorslot_send_sms`
action for a site-specific SMS provider or add-on, but this release does not
include a paid SMS transport.

= What happens when the plugin is removed? =

Deactivation removes TutorSlot scheduled actions but keeps bookings and
settings. Destructive data removal is disabled by default and must be enabled
explicitly before uninstalling.

= Where can I get help? =

Start with the user guide at
https://github.com/itsmanzur/tutorslot/blob/main/docs/USER-GUIDE.md and the
support checklist at https://github.com/itsmanzur/tutorslot/blob/main/SUPPORT.md.
Use https://github.com/itsmanzur/tutorslot/issues for reproducible,
non-sensitive bugs. Send vulnerabilities privately to security@tutorslot.com.

== Changelog ==

= 0.1.0 =
* Initial pre-release build of the TutorSlot booking platform.
* Added visual weekly availability, one-off closures and tutor-specific subjects.
* Added single and recurring lesson booking with overlap and booking-race protection.
* Added student and parent dashboards, linked learners and family booking support.
* Added lesson packages, atomic credit spending, expiry, rollover and refund handling.
* Added tutor-managed rescheduling, cancellation, attendance, lesson notes and CSV export.
* Added optional Stripe and bKash payments, offline payment and package-credit checkout.
* Added optional Google Meet and Zoom meetings with signed, expiring join links.
* Added scheduled email reminders, parent copies and an SMS provider integration hook.
* Added WordPress privacy policy text, personal-data export and erasure support.
* Hardened REST authorization, booking ownership, webhook replay protection, secret masking,
  SQL boundaries, output escaping, audit logging and plugin lifecycle cleanup.
* Added migration, uninstall, unit, MySQL integration, Playwright E2E, visual,
  accessibility and performance regression coverage for release readiness.
* Added an interactive English Help & Docs page with searchable feature guides,
  a persistent quick-start checklist, written video outline and configurable
  external video-platform link.
