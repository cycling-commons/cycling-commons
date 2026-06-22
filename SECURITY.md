# Security Policy

The open Commons is **non-personal** map data — but the platform also holds account data
(emails, salted password hashes) and security logs (IP addresses), and the platform, API,
and site can have vulnerabilities. We want to hear about them. Thank you for helping keep
riders and the Commons safe.

## Reporting a vulnerability

**Please report privately first — do not open a public issue for a security problem.**

- **Email:** development@cyclingcommons.org
- **GitHub:** use [private vulnerability reporting](https://github.com/cycling-commons/cycling-commons/security/advisories/new) (Security → Report a vulnerability), if enabled.
- **Languages:** English, Nederlands, Français.

This mirrors the machine-readable contact in
[`/.well-known/security.txt`](atlas/demo/.well-known/security.txt) (RFC 9116).

Please include enough detail to reproduce: affected URL/endpoint or component, steps, and
the impact you observed. Proof-of-concept code is welcome.

## What to expect

- We aim to **acknowledge** your report within a few days and to keep you updated as we
  investigate.
- We'll work with you on a fix and a disclosure timeline, and we're happy to **credit** you
  once a fix has shipped (or to keep you anonymous — your choice).
- We do not currently run a paid bug-bounty program. This is a community project; reports are
  handled in good faith on both sides.

## Scope

In scope: the platform code (API, pipeline, site), the public API, authentication and the
contribution write-path, and anything that could expose data the Commons promises never to hold
(personal data, raw movement traces — see the [Manifesto](wiki/manifesto.md) §IV).

Out of scope: findings that require a compromised device or account, social engineering, volumetric
denial-of-service, and issues in third-party services we merely link to.

## Please don't

- Access, modify, or delete data that isn't yours, or run tests that degrade the service for others.
- Publicly disclose a vulnerability before we've had a reasonable chance to fix it.

Acting in good faith under this policy, we will not pursue or support legal action against you for
your research.
