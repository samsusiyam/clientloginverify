# 2FA Email Verification

Email based two factor authentication (2FA) for WHMCS client logins, by **Host Nibo**.
Support: [https://siyam.bio.link/](https://siyam.bio.link/)

After a client enters the correct password, a one time verification code is emailed
to their registered address. Every client area page stays locked until the code is
entered, protecting accounts even if a password is stolen.

## Features

- Protected with **Host Nibo ELMS Licensing**.
- Email delivered 6 digit codes (configurable 4-8 digits), hashed at rest.
- Codes expire (default 5 minutes) and lock after a configurable number of attempts.
- **Remember This Device (Trusted Devices)**: Allow clients to trust their browser for X days (default 30 days) and bypass 2FA on subsequent logins.
- **Emergency Recovery Backup Codes**: Instant generation, download, print, and copyable emergency backup codes.
- **Live Resend Countdown Timer**: Interactive frontend timer on the verification screen showing real-time remaining cooldown seconds.
- **Multilingual Support**: Fully localized with English and Bengali (`lang/english.php`, `lang/bengali.php`, `lang/bangla.php`) with dynamic language detection.
- **Deep Link / Return URL Retention**: Preserves the client's destination page after successful verification.
- Per-IP brute force throttle across all accounts (50 failures / 15 minutes).
- Resend with cooldown and a maximum resend limit, enforced atomically.
- Force mode (all clients) or opt-in mode (only enabled clients).
- Per-client enable/disable and excluded client groups.
- Admin dashboard, in-page settings editor, client manager with search, and filterable logs with pagination.
- CSRF protected admin and client forms.
- Modern SVG UI with responsive light/dark theme support.
- Fail closed: if the email cannot be sent the client stays locked out (see below).

## Requirements

- WHMCS 7.0+ / 8.x / 9.x (uses the addon module system and Capsule ORM).
- PHP 7.2 or newer (tested against PHP 8.1, 8.2, 8.3).

## Installation

1. Copy this folder to `modules/addons/clientloginverify/` on your WHMCS install
   (or `git pull` if it is already a repository there).
2. Go to **Setup → Addon Modules**, find **2FA Email Verification** and click
   **Activate**, then grant access to the appropriate admin roles.
3. Open the module (**Addons → 2FA Email Verification**) and enter your ELMS license key on the **License** tab.
4. On the **Settings** tab use **Send Test Email** with a real client ID to confirm
   your WHMCS mail delivery works before relying on 2FA.

If you upgraded from an older version, clear the template cache:
**Setup → System Settings → System Cleanup → Empty Template Cache**.

## Support

Need help, custom feature requests, or license reissues?
Contact via: [https://siyam.bio.link/](https://siyam.bio.link/)

## License

Proprietary. © Host Nibo. Protected by Host Nibo ELMS.
