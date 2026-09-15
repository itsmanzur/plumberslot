# PlumberSlot security policy

PlumberSlot handles appointment schedules, customer contact and service-address
details, payment references and meeting links. Please report security issues
privately so they can be investigated and fixed before public disclosure.

## Supported versions

PlumberSlot is currently an unreleased `0.1.0` pre-release. Until the first
release is tagged, security fixes are applied only to the latest code on the
`main` branch. Older commits and untagged ZIP copies are not supported.

After the first public release, this section will identify the supported
release line. Sites should update to the latest supported release before
requesting security support.

## Report a vulnerability

Email **security@plumberslot.com** with the subject:

```text
[SECURITY] PlumberSlot: short summary
```

Do not include a vulnerability, exploit or sensitive user data in a public
GitHub issue, discussion, support request or social-media post.

Please include as much of the following as is safe:

- the affected PlumberSlot version, commit or ZIP source;
- WordPress, PHP and browser versions relevant to the issue;
- the required user role, account relationship and other preconditions;
- clear reproduction steps and the observed security impact;
- a minimal proof of concept with secrets and personal data removed;
- suggested mitigations or fixes, if known;
- how you would like to be credited, or whether you prefer anonymity.

Never send real customer, payment or meeting credentials, or a real service
address. Replace them with synthetic values and redact logs before attaching
them.

## What happens next

We aim to:

- acknowledge a complete report within three business days;
- provide an initial validation and severity assessment within seven business
  days;
- send a status update at least every fourteen days while a confirmed issue is
  being fixed; and
- coordinate disclosure after a fix is available and affected users have had
  a reasonable opportunity to update.

These are response targets, not guarantees. Complex issues or third-party
coordination may take longer. If a report is not reproducible, more information
may be requested.

Confirmed fixes should include regression coverage where practical. Public
release notes will credit the reporter if requested and will avoid details that
would unnecessarily expose sites that have not updated.

## In scope

Examples include:

- authentication, authorization, capability or ownership bypasses;
- IDOR, SQL injection, XSS, CSRF or unsafe file/data handling;
- exposure of API secrets, payment references, meeting links or personal data;
- booking-race, credit, refund or webhook flaws with security or financial
  impact;
- privacy exporter, eraser, uninstall or audit-log boundary failures; and
- vulnerable code copied into PlumberSlot's distributed runtime assets.

## Out of scope

Unless PlumberSlot caused or materially worsened the issue, the following are out
of scope:

- WordPress core, a theme, another plugin or a third-party provider service;
- compromised hosting, administrator accounts or API credentials;
- configuration recommendations without a demonstrated security impact;
- self-XSS, missing security headers controlled by the host, or scanner-only
  findings without a reproducible exploit path;
- denial-of-service testing, spam, social engineering or physical attacks; and
- testing against a site or account you do not own or have written permission
  to assess.

## Responsible testing

Use an isolated test installation and synthetic accounts. Do not access,
modify, retain or disclose another person's data, including a real service
address. Do not test with real customer accounts, live payment methods or
production meeting-provider accounts. Avoid destructive actions, persistence,
automated traffic that affects availability and any attempt to move beyond the
minimum proof required to demonstrate the issue.

Stop testing and report immediately if you encounter real personal data,
credentials or an active compromise. This policy does not authorize activity
that would otherwise be unlawful, and PlumberSlot does not currently offer a paid
bug bounty.
