# Client Login Verify

A WHMCS **Addon Module** that adds email-based two-factor authentication (2FA) to the
client login flow. After a client logs in, a one-time PIN is emailed to them and all
client area access is blocked until the PIN is verified — an extra layer of security
against unauthorized account access.

**Version: 2.0.0**

## Features

- 6-digit (configurable 4–8) random OTP generated with `random_int()`
- OTP expiry (default 5 minutes)
- New OTP generated on every login (previous pending codes invalidated)
- Email delivery through the WHMCS built-in email system
- Configurable WHMCS email template
- Maximum verification attempts (brute-force protection, atomic + race-safe)
- Resend OTP with cooldown + max resend limits (atomic + race-safe)
- OTP stored **hashed** (`password_hash`) — never plaintext
- All timestamps stored in **UTC** (no timezone mismatch issues)
- IP address & User-Agent logging (configurable)
- Successful / failed / email-failure verification logging
- Session regeneration on success and logout (session-fixation safe)
- `ClientAreaPageLogin` + `ClientAreaPage` guards (hooks run at priority 100)
- Verify page requires a valid pending login flow — no OTP auto-generation on direct access
- Email-failure handled safely (user sees an error, never silently let through)
- Scheduled cleanup via `DailyCronJob`
- Enable/Disable module
- Force 2FA for every client
- Excluded client groups (comma-separated IDs)
- Per-client 2FA enable/disable override (admin)
- Admin "Client 2FA Status" + "Logs" views
- Responsive verification page
- WHMCS v8.x compatible

> **Trusted Device (30-day remember)** is planned for a future release.

## Installation

1. Copy the `clientloginverify/` folder into your WHMCS installation:
   ```
   WHMCS/modules/addons/clientloginverify/
   ```
2. Go to **Setup → Addon Modules** in the WHMCS admin area.
3. Activate **Client Login Verify**.
   - On activation the module creates its database tables and a client email
     template named **Client Login Verification**.
4. Configure the module settings and click **Save Changes**.
5. (Optional) Review the email template under **Setup → Email Templates** and
   adjust the wording/branding.

## Configuration

| Setting             | Default | Description                                          |
|---------------------|---------|------------------------------------------------------|
| Enable Module       | Yes     | Master on/off switch                                  |
| Force Verification  | Yes     | Require 2FA for every client login                   |
| OTP Length          | 6       | Number of digits in the OTP                           |
| OTP Expiry          | 5       | Minutes the OTP remains valid                        |
| Maximum Attempts    | 5       | Incorrect entries before the code is invalidated     |
| Resend Cooldown     | 60      | Seconds between resend requests                       |
| Maximum Resends     | 3       | Max resend requests per code                          |
| Email Template      | Client Login Verification | WHMCS client email template to use       |
| Log Attempts        | Yes     | Write verification events to the log table           |
| Log IP Address      | Yes     | Record client IP in the log table                     |
| Excluded Groups     | (empty) | Comma-separated client group IDs to skip 2FA         |

## How it works

1. A client logs in successfully → the `ClientLogin` hook fires.
2. The module generates a hashed OTP, stores it, emails it, and sets a session
   flag `clv_2fa_passed = false`.
3. The client is redirected to the verification page
   (`index.php?m=clientloginverify&clvverify=1`).
4. The `ClientAreaPage` hook blocks every other client page until verification
   passes. The verification page is detected via the custom `clvverify` parameter
   (WHMCS strips `m` before this hook, so a custom parameter is used to avoid
   redirect loops).
5. The client enters the OTP. On success the session flag is set to `true` and
   they are redirected into the client area.

## File structure

```
clientloginverify/
├── clientloginverify.php   # config, activate, deactivate, admin output, client area
├── hooks.php              # ClientLogin, ClientAreaPageLogin, ClientAreaPage guards, head/footer, logout, cron
├── whmcs.json             # WHMCS 8.x module manifest
├── lib/
│   ├── Time.php           # centralized UTC time helpers
│   ├── OTP.php            # generate / verify / hash OTP
│   ├── Security.php       # 2FA rules, resend limits, per-client overrides
│   ├── Mailer.php         # send OTP email via WHMCS
│   ├── Logger.php         # verification event logging
│   └── Session.php        # WHMCS\Session wrapper
├── templates/
│   ├── admin.tpl          # admin dashboard
│   ├── verify.tpl         # client OTP form
│   └── settings.tpl       # client 2FA status + logs
├── assets/
│   ├── css/clientloginverify.css
│   └── js/clientloginverify.js
├── lang/english.php
└── README.md
```

## Database tables

- `mod_clientloginverify_codes` — active OTP codes (hashed)
- `mod_clientloginverify_logs` — verification events
- `mod_clientloginverify_settings` — per-client 2FA overrides

## Notes

- Settings are stored in WHMCS's `tbladdonmodules` (global) and
  `mod_clientloginverify_settings` (per-client).
- Deactivating the module preserves all data.
