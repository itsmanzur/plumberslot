=== PlumberSlot ===
Contributors: plumberslot
Tags: booking, plumber, appointment, field-service, scheduling
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scheduling for plumbing businesses with technician-owned availability, service
addresses, service plans, optional payments and virtual estimates.

== Description ==

Most booking plugins were built for salons and grew a "services" page later.
It shows: every job is treated as if it happens at the business's own counter,
one calendar covers everyone on staff, and there is nowhere to put the
customer's address — even though the whole job happens there.

PlumberSlot starts from how field-service booking actually works.

* **Visual weekly availability** — paint 30-minute cells per technician and
  add one-off closed dates without rebuilding the whole timetable.
* **Technician-specific services** — set category, duration, price and an
  optional free-estimate flag on each service a technician offers.
* **Public booking flow** — publish it with the PlumberSlot block or the
  `[plumberslot]` shortcode. Visitors can browse services and open times; a
  WordPress account is required to confirm a booking.
* **Service address on every booking** — a dedicated widget step collects the
  job site's address (line 1, line 2, city, state, zip). It travels with the
  booking to the technician's dashboard and the confirmation email so the
  right crew shows up at the right door.
* **Single and recurring appointments** — book one appointment or choose
  multiple weekdays and a series length for a recurring job. Individual
  appointments can be moved without moving the remaining series.
* **Multiple technicians, independent calendars** — a solo plumber or a small
  team; each technician keeps their own weekly availability, services and
  bookings.
* **Service Plans** — issue technician/service credits, spend them atomically
  with a booking and track balances, expiry, refunds and rollover policy.
  Plan issuance is separate from online gateway checkout in this release.
* **Booking lifecycle tools** — technicians can manage bookings, reschedule or
  cancel, record job completion, save job notes and export booking CSV data.
* **Optional payments** — Stripe and bKash are built in. Sites can also allow
  direct payment to the technician or Service Plan credits; WooCommerce is
  not required.
* **Optional virtual estimates** — connect Google Meet or configure Zoom for a
  video consultation before an on-site visit. Participant join links are
  signed and time-limited.
* **Notifications and privacy** — scheduled 24-hour and 1-hour email
  reminders that include the job's service address, WordPress personal-data
  export/erasure and suggested privacy policy text. SMS delivery is available
  through a developer hook and requires a separate provider integration.
* **Role-aware REST API** — public availability is intentionally readable;
  protected booking, technician, payment and administration operations use
  nonce, capability and ownership checks.
* **Interactive Help & Docs** — a plain-English in-product guide introduces
  PlumberSlot, explains its field-service-first differences, saves four-step
  setup progress, searches feature walkthroughs and links to an externally
  hosted quick-tour video while keeping a written outline in the plugin.

= Security =

Booking plugins hold customers' names, contact details and, for on-site
trades, the address of the property they'll be visiting. PlumberSlot treats
that as the sensitive data it is:

* Public REST access is limited to technician profiles, services and open
  slots. Protected reads are account-scoped; state-changing routes validate a
  REST nonce plus the applicable capability and ownership rules.
* Dynamic SQL values are prepared and table identifiers come from an internal
  schema whitelist.
* Rendered PHP output is escaped, and the Preact interfaces render remote data
  as text rather than HTML.
* Meeting emails use signed, expiring PlumberSlot URLs instead of raw provider
  meeting URLs.
* Configured API secrets are encrypted at rest and masked in API responses.
* Sensitive booking, payment, meeting, settings and privacy actions are written
  to an audit log.
* Webhooks are signature-checked and stored for replay protection.
* Coding standards, static analysis and a dependency audit run on every commit.

Report vulnerabilities privately to security@plumberslot.com. Do not publish
exploit details in a public issue. The full reporting and responsible-testing
policy is at https://github.com/itsmanzur/plumberslot/blob/main/SECURITY.md.

== Installation ==

1. Install and activate.
2. The setup wizard opens automatically — four steps, about ninety seconds.
3. Add the PlumberSlot booking block to a page, or paste
   `[plumberslot technician="your-name"]` into a shortcode block.
4. Configure Stripe, bKash, Google Meet or Zoom under PlumberSlot settings only
   when those optional services are needed.
5. Customers sign in to confirm appointments; technicians use their own
   dashboard.

== External services ==

PlumberSlot can schedule appointments without contacting any payment or
meeting provider. The following optional services are contacted only after a
site administrator configures them and a user invokes the related feature. The
site owner is responsible for providing any notices or obtaining any consent
required for its use of these services.

= Stripe =

When a customer chooses card payment, PlumberSlot creates a Stripe-hosted Checkout
session. It sends the site's Stripe credential, booking identifier, amount,
currency, a generic "Service Call" item name and success/cancel URLs. Refund
requests send the payment reference and refund amount. Card details are
entered on Stripe's hosted page and do not pass through or get stored by
PlumberSlot. Stripe also sends signed payment-status webhooks back to the site.

Privacy policy: https://stripe.com/privacy
Services agreement: https://stripe.com/legal/ssa

= bKash =

When a customer chooses bKash, PlumberSlot authenticates with the configured
merchant credentials and sends a booking-derived payer reference and invoice
number, amount in BDT and a callback URL to bKash Tokenized Checkout. Completing,
checking or refunding a payment sends its bKash payment/transaction reference;
refunds also include the amount, a `service_call` line reference and a
cancellation reason. Account, OTP and PIN details are handled on bKash's pages
and are not stored by PlumberSlot.

Privacy notice: https://www.bkash.com/en/page/privacy-notice
Payment gateway terms: https://www.bkash.com/en/page/tokenized_checkout

= Google Calendar and Google Meet =

When a technician chooses to connect Google Meet, PlumberSlot uses Google
OAuth with the `calendar.events` permission. OAuth exchanges send the
configured client credentials, authorization or refresh token and this site's
callback URL. For a confirmed appointment that includes a virtual estimate,
PlumberSlot sends the customer's WordPress display name in the event title,
appointment start/end time and a booking-derived conference request
identifier to Google Calendar. It later sends the event identifier when
resolving the Meet join URL. PlumberSlot does not add customer email
addresses as Google Calendar attendees, and does not send the job's service
address to Google.

Privacy policy: https://policies.google.com/privacy
Terms of service: https://policies.google.com/terms

= Zoom =

When Zoom is configured, PlumberSlot exchanges the site's server-to-server OAuth
account/client credentials for an access token. It sends the customer's
WordPress display name in the meeting topic, appointment start time and
duration, and secure meeting settings to create a meeting. The returned
meeting identifier is sent again when resolving a join URL or deleting a
cancelled meeting. PlumberSlot sets automatic recording to "none" and does not
send the job's service address to Zoom.

Privacy statement: https://www.zoom.com/en/trust/privacy/privacy-statement/
Terms of service: https://www.zoom.com/en/trust/terms/

= Email and SMS delivery =

Email is sent through the site's standard WordPress `wp_mail` configuration;
PlumberSlot does not bundle an external email delivery service. PlumberSlot also
does not contact an SMS provider itself. If the site enables SMS and installs
code that handles the `plumberslot_send_sms` action, that code receives the
recipient's mobile number, the reminder or cancellation message and the
booking record (which includes the service address). The site owner must
document the selected email/SMS provider and its data practices.

== Screenshots ==

1. Customers choose a technician-specific service in the responsive public booking widget.
2. Technicians paint weekly open hours and manage service defaults from one timetable.
3. Customers enter the job's service address before reviewing the held time and appointment details.
4. The completion screen presents the booking summary, calendar action and dashboard link.
5. Technicians see their assigned jobs, service addresses and job notes in one dashboard.
6. The public service picker stays touch-friendly and readable on a 390px mobile viewport.

== Frequently Asked Questions ==

= Can I run this with more than one technician? =

Yes. Each technician keeps independent weekly availability, services and
bookings. Administrators manage technician accounts and site-wide settings;
technicians only manage their own schedule and jobs.

= Do I need WooCommerce? =

No. Payments are optional entirely, and when you do turn them on you can use
Stripe or bKash directly without WooCommerce. You can also accept payment to
the technician outside the site or use Service Plan credits.

= Can visitors book without an account? =

Visitors can view technician details, services and open times. They must sign
in to hold a time and confirm a single or recurring appointment. This lets
PlumberSlot apply ownership checks and connect appointments to the correct
customer and technician dashboards.

= Which meeting providers are included, and are they the on-site visit? =

No — the meeting providers are for an optional virtual estimate or
consultation, not the on-site job itself. Google Meet uses a technician OAuth
connection and Google Calendar. Zoom uses a server-to-server OAuth application
configured by the site. Virtual estimates are optional; the on-site
appointment and its service address work without a connected meeting
provider.

= Does PlumberSlot send SMS messages itself? =

Email notifications are included. PlumberSlot exposes the `plumberslot_send_sms`
action for a site-specific SMS provider or add-on, but this release does not
include a paid SMS transport.

= What happens when the plugin is removed? =

Deactivation removes PlumberSlot scheduled actions but keeps bookings and
settings. Destructive data removal is disabled by default and must be enabled
explicitly before uninstalling.

= Where can I get help? =

Start with the user guide at
https://github.com/itsmanzur/plumberslot/blob/main/docs/USER-GUIDE.md and the
support checklist at https://github.com/itsmanzur/plumberslot/blob/main/SUPPORT.md.
Use https://github.com/itsmanzur/plumberslot/issues for reproducible,
non-sensitive bugs. Send vulnerabilities privately to security@plumberslot.com.

== Changelog ==

= 0.1.0 =
* Initial pre-release build of the PlumberSlot booking platform.
* Added visual weekly availability, one-off closures and technician-specific services.
* Added single and recurring appointment booking with overlap and booking-race protection.
* Added a structured service address on every booking, surfaced on the technician
  dashboard and in confirmation emails.
* Added multi-technician support, with independent per-technician calendars and services.
* Added Service Plans with atomic credit spending, expiry, rollover and refund handling.
* Added technician-managed rescheduling, cancellation, job completion, job notes and CSV export.
* Added optional Stripe and bKash payments, offline payment and Service Plan checkout.
* Added optional Google Meet and Zoom virtual estimates with signed, expiring join links.
* Added scheduled email reminders with service-address details and an SMS provider integration hook.
* Added WordPress privacy policy text, personal-data export and erasure support.
* Hardened REST authorization, booking ownership, webhook replay protection, secret masking,
  SQL boundaries, output escaping, audit logging and plugin lifecycle cleanup.
* Added migration, uninstall, unit, MySQL integration, Playwright E2E, visual,
  accessibility and performance regression coverage for release readiness.
* Added an interactive English Help & Docs page with searchable feature guides,
  a persistent quick-start checklist, written video outline and configurable
  external video-platform link.
