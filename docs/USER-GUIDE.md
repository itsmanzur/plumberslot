# PlumberSlot user guide

This guide describes the current unreleased `0.1.0` pre-release. Menu labels
and workflows may still change before the first public release.

## 1. Install and activate

PlumberSlot requires WordPress 6.4+ and PHP 8.1+. Install a complete release ZIP,
then activate **PlumberSlot** under **Plugins**. A source checkout also needs
`composer install`, `npm ci` and `npm run build` before it is feature-complete.

Activation creates PlumberSlot's database tables and the Technician and
Customer roles. The setup wizard opens once for a user who can manage
PlumberSlot.

For optional payment or meeting credentials, first add a stable encryption key
to `wp-config.php`:

```php
define( 'PLUMBERSLOT_ENCRYPTION_KEY', '<a long random secret>' );
```

Keep this key outside the database and do not rotate it without reconnecting
configured services. WordPress `AUTH_KEY` is used as a fallback.

## 2. Finish onboarding

The four-step wizard asks for:

1. **Business mode** — solo technician or a small team.
2. **Services** — choose suggested services or add your own.
3. **Availability** — paint the weekly cells customers may book.
4. **Payments** — enable online payments now or configure them later.

Finishing setup creates or updates the technician profile and publishes a
**Book an appointment** page containing the technician-specific shortcode.

## 3. Configure the timetable

Open **PlumberSlot → Availability**.

- Paint open weekly cells and save the timetable.
- Add one-off closed dates for holidays or exceptions.
- Confirm the technician timezone before publishing slots.
- Remember that lead time, service duration and buffer settings can remove an
  apparently open time from the public list.

PlumberSlot stores times in UTC and displays them in the applicable
technician/customer timezone.

## 4. Configure services

Open **PlumberSlot → Services**. Each service belongs to one technician and can
define its category, duration, price and free-estimate status. A booking
request is rejected if the requested service does not belong to the selected
technician.

For multi-technician sites, administrators manage technician accounts under
**PlumberSlot → Technicians**. Technicians can manage their own availability,
services and bookings but do not receive site-wide settings access.

## 5. Publish booking

Use the **PlumberSlot booking** block, or add a shortcode:

```text
[plumberslot technician="technician-slug"]
```

Optional shortcode attributes:

```text
[plumberslot technician="technician-slug" service="123"]
```

Visitors can browse the technician, service and open times. A WordPress
account with booking permission is required before a slot can be held or
confirmed. Confirming a booking also collects the job's service address
(street, unit/line 2, city, state and zip) in a dedicated widget step.

The booking flow supports one appointment or a recurring weekly series. A
recurring series may include multiple weekdays and an appointment count; an
individual appointment can later be moved without shifting the remaining
series.

## 6. Customer and technician access

PlumberSlot registers Customer and Technician roles.

- Customers book for themselves through the public widget; there is no
  separate family or multi-person account — each booking belongs to the
  signed-in customer. Confirmation emails include the appointment details and
  service address.
- Technicians manage their own schedule, bookings, job outcomes and job notes
  from their dashboard.
- Administrators manage all technicians and global settings.

The technician front-end dashboard is available at `/technician-dashboard/`
after WordPress permalinks are active. It may also be embedded with:

```text
[plumberslot_dashboard]
```

## 7. Payments and Service Plans

Open **PlumberSlot → Settings → Connections** to configure optional Stripe or
bKash credentials. Online payments remain unavailable until payments are
enabled and a gateway is fully configured.

- Stripe uses hosted Checkout; PlumberSlot does not collect raw card details.
- bKash Tokenized Checkout accepts BDT only.
- Sites may allow payment directly to the technician.
- Service Plan credits can be issued and spent against an eligible
  technician/service; plan issuance is separate from gateway checkout in this
  release.

Configure gateway webhooks/callbacks on the site's canonical HTTPS URL. See the
[external-service disclosure](../readme.txt) before enabling a
provider.

## 8. Virtual estimates and notifications

Google Meet uses a technician OAuth connection and Google Calendar. Zoom uses a
site-configured server-to-server OAuth application. These providers create a
virtual meeting for an optional video estimate or consultation — not the
on-site job itself. PlumberSlot creates meetings after eligible bookings are
confirmed and sends participants a signed, time-limited PlumberSlot join URL
instead of the raw provider URL.

Email uses the site's WordPress mail configuration and includes the job's
service address. Reminder jobs require Action Scheduler and a functioning
WordPress cron runner. SMS is not delivered by PlumberSlot itself; an add-on
must handle the `plumberslot_send_sms` action.

## 9. Manage a booking

Open **PlumberSlot → Bookings** to view appointments, reschedule or cancel,
record job completion, save job notes and export CSV data. State transitions
are restricted by role and the current booking status. See the
[booking lifecycle policy](BOOKING-LIFECYCLE-POLICY.md) for the supported
transitions and side effects.

## 10. Privacy, deactivation and uninstall

PlumberSlot registers WordPress personal-data export and erasure callbacks and
adds suggested text to the WordPress Privacy Policy Guide. Review that text and
the external-service disclosure before publishing the site's privacy policy.

Deactivation removes PlumberSlot scheduled actions but preserves data. Uninstall
also preserves data unless **Delete all PlumberSlot data when the plugin is
removed** was explicitly enabled in advanced settings before deletion.

## Getting help

Follow [`SUPPORT.md`](../SUPPORT.md) for common checks and safe diagnostic
details. Security issues must be sent privately according to
[`SECURITY.md`](../SECURITY.md).
