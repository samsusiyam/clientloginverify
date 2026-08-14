# Client Login Verify

Email based two factor authentication (2FA) for WHMCS client logins, by **Host Nibo**.

After a client enters the correct password, a one time verification code is emailed
to their registered address. Every client area page stays locked until the code is
entered, protecting accounts even if a password is stolen.

## Features

- Email delivered 6 digit codes (configurable 4-8 digits), hashed at rest.
- Codes expire (default 5 minutes) and lock after a configurable number of attempts.
- Per-IP brute force throttle across all accounts (50 failures / 15 minutes).
- Resend with cooldown and a maximum resend limit, enforced atomically.
- Force mode (all clients) or opt-in mode (only enabled clients).
- Per-client enable/disable and excluded client groups.
- Admin dashboard, in-page settings editor, client manager and filterable logs.
- CSRF protected admin and client forms; session id regenerated after success.
- Fail closed: if the email cannot be sent the client stays locked out (see below).

## Requirements

- WHMCS 7.0+ (uses the addon module system and Capsule ORM).
- PHP 7.2 or newer (tested against PHP 8.3 / cPanel ea-php83).

## Installation

1. Copy this folder to `modules/addons/clientloginverify/` on your WHMCS install
   (or `git pull` if it is already a repository there).
2. Go to **Setup → Addon Modules**, find **Client Login Verify** and click
   **Activate**, then grant access to the appropriate admin roles.
3. Open the module (**Addons → Client Login Verify**) and review the **Settings** tab.
4. On the **Settings** tab use **Send Test Email** with a real client ID to confirm
   your WHMCS mail delivery works before relying on 2FA.

If you upgraded from an older version, clear the template cache:
**Setup → System Settings → System Cleanup → Empty Template Cache**.

## Settings

| Setting | Default | Notes |
|---|---|---|
| Enable Module | on | Master kill switch. |
| Force Verification | on | On = all clients; off = only clients enabled on the Clients tab. |
| Code Length | 6 | 4-8 digits. |
| Code Expiry (minutes) | 5 | 1-60. |
| Maximum Attempts | 5 | 1-10 before the code locks. |
| Resend Cooldown (seconds) | 60 | 0-600. |
| Maximum Resends | 3 | 0-10. |
| Email Template | Client Login Verification | Created automatically on activation. |
| Log Attempts | on | Enables the Logs tab. |
| Log IP Address | on | Required for the per-IP throttle. |
| Excluded Client Groups | (empty) | Comma separated group IDs, e.g. `1,3`. |

## Emergency access (fail closed)

This module intentionally fails **closed**: if outbound email breaks, clients cannot
receive codes and therefore cannot log in. To recover quickly:

- Toggle **Enable Module** to off on the Settings tab, or
- Run this SQL from phpMyAdmin to disable it directly:

  ```sql
  UPDATE tbladdonmodules
     SET value = ''
   WHERE module = 'clientloginverify'
     AND setting = 'enableModule';
  ```

- To rescue a single client, use **Disable 2FA** / **Clear pending code** on the
  Clients tab.

## Data and uninstalling

Deactivating the module keeps all tables and data, so reactivating restores
everything. The module uses three tables (created on activation):

- `mod_clientloginverify_codes`
- `mod_clientloginverify_logs`
- `mod_clientloginverify_settings`

To remove all data permanently, drop those three tables manually after deactivating.

## Known limitation

The client area guard runs on the `ClientAreaPage` hook, which covers all templated
client pages. Non-templated endpoints such as `dl.php` do not fire this hook, the
same limitation that applies to the built-in WHMCS 2FA.

## License

Proprietary. © Host Nibo.
