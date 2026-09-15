# PlumberSlot support

PlumberSlot is currently an unreleased `0.1.0` pre-release. Community support is
provided on a best-effort basis; there is no guaranteed response time or paid
support plan in this release.

## Start here

1. Read the [user guide](docs/USER-GUIDE.md) and the FAQ in
   [`readme.txt`](readme.txt).
2. Confirm the problem still occurs on the latest `main` commit or latest
   supported release when releases become available.
3. Reproduce it on a staging site with a default WordPress theme and unrelated
   plugins disabled when that is safe.
4. Search existing [GitHub issues](https://github.com/itsmanzur/plumberslot/issues).
5. Open a new GitHub issue for a reproducible, non-sensitive bug.

Do not use a public issue for vulnerabilities. Send those privately according
to [`SECURITY.md`](SECURITY.md).

## Information to include

- PlumberSlot version or commit;
- WordPress and PHP versions;
- browser/device and active theme when the issue is visual;
- the user's WordPress role and the workflow being attempted;
- exact reproduction steps, expected result and actual result;
- a screenshot or short screen recording when useful;
- relevant PHP, browser-console or REST response errors; and
- whether the problem remains with caches cleared and unrelated plugins
  disabled on staging.

Remove API keys, nonces, cookies, authorization headers, payment/meeting
references and all customer personal data, including service addresses.
Never post a database dump or production access credentials.

## Common checks

### The admin screen or widget is blank

- A source checkout needs `npm ci && npm run build`; an official release ZIP
  should already contain `assets/dist`.
- Hard-refresh the browser and clear page/CDN/minification caches.
- Check the browser console and the failed `/wp-json/plumberslot/v1/` request.
- Temporarily reproduce with a default theme to isolate CSS/JavaScript conflicts.

### No technician, service or time appears

- Confirm the technician is active and has a service assigned to that same
  technician.
- Save at least one weekly availability cell and check one-off closed dates.
- Check technician timezone, service duration, lead time and buffer settings.
- Clear persistent object/page caches after changing availability.

### A customer cannot confirm

Browsing is public, but holding and confirming require a signed-in WordPress
account with booking permission. Confirm the account role, refresh the page to
renew the REST nonce and choose an open time again if the hold expired.

### Reminders or expiry jobs do not run

- Confirm the production Action Scheduler dependency is available.
- Check **Tools → Scheduled Actions** for pending or failed PlumberSlot actions.
- Verify WordPress cron is not disabled without a real server cron replacement.
- Test the site's `wp_mail` delivery independently.

### Stripe or bKash checkout fails

- Enable payments and complete the selected gateway credentials.
- Use the site's canonical HTTPS URL for callbacks/webhooks.
- Confirm Stripe webhook signing secret and delivery status.
- bKash accepts BDT only; verify sandbox/live credentials match the selected
  mode.
- Do not share provider secrets when requesting support.

### Google Meet or Zoom does not connect

- Define a stable `PLUMBERSLOT_ENCRYPTION_KEY` or confirm WordPress `AUTH_KEY` has
  not changed since credentials were saved.
- Verify OAuth callback URLs and required provider permissions.
- Reconnect Google for the affected technician, or recheck the Zoom account/client
  credentials.
- Signed join URLs are account-bound and time-limited; test with an authorized
  participant near the appointment time.

### Dashboard or join URL returns 404

Visit **Settings → Permalinks** and save once to refresh rewrite rules. Then
clear page/server caches and retry the canonical URL.

## Support boundaries

Support covers reproducible PlumberSlot defects and documentation corrections.
Custom theme work, hosting configuration, deliverability, provider account
approval, gateway fees, custom SMS integrations and conflicts caused entirely
by another product may require help from that provider or a developer.

External-service data sharing is documented in
[`readme.txt`](readme.txt). Third-party license notices are in
[`THIRD-PARTY-LICENSES.txt`](THIRD-PARTY-LICENSES.txt).
