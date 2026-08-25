<div class="clv-verify-container" id="clv_container">
    <div class="clv-card">
        {if $view_mode == 'security' || $view_mode == 'devices'}
            <div class="clv-icon-wrapper clv-icon-shield">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    <polyline points="9 12 11 14 15 10"></polyline>
                </svg>
            </div>
            <h2 class="clv-title">{$lang.security_center|default:'2FA Security Center'}</h2>
            <p class="clv-text">{$lang.backup_codes_desc|default:'Manage your login security, emergency recovery codes, and trusted browsers.'}</p>

            {if $info}
                <div class="clv-alert clv-alert-info">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="16" x2="12" y2="12"></line>
                        <line x1="12" y1="8" x2="12.01" y2="8"></line>
                    </svg>
                    <span>{$info}</span>
                </div>
            {/if}

            <!-- Backup Codes Section -->
            <div class="clv-security-section">
                <h3 class="clv-section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    {$lang.backup_codes_title|default:'Emergency Backup Codes'}
                </h3>
                
                {if $new_backup_codes && count($new_backup_codes) > 0}
                    <div class="clv-backup-codes-grid" id="clv_backup_codes_box">
                        {foreach from=$new_backup_codes item=bcode}
                            <div class="clv-backup-code-pill"><code>{$bcode}</code></div>
                        {/foreach}
                    </div>

                    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
                        <button type="button" class="clv-btn-sm clv-btn-primary" onclick="clvCopyBackupCodes();">
                            📋 {$lang.copy_codes|default:'Copy All'}
                        </button>
                        <button type="button" class="clv-btn-sm clv-btn-secondary" onclick="clvDownloadBackupCodes();">
                            📥 {$lang.download_codes|default:'Download (.txt)'}
                        </button>
                        <button type="button" class="clv-btn-sm clv-btn-secondary" onclick="clvPrintBackupCodes();">
                            🖨️ {$lang.print_codes|default:'Print'}
                        </button>
                    </div>
                {else}
                    <p style="font-size:13px;color:var(--clv-text-secondary);margin:6px 0 12px;text-align:left;">
                        {if $remaining_backup_codes > 0}
                            {$lang.backup_codes_count|replace:':count':$remaining_backup_codes|default:"You currently have `$remaining_backup_codes` active backup code(s) remaining."}
                        {else}
                            You have no active backup codes generated yet.
                        {/if}
                    </p>

                    <form method="post" action="{$generate_codes_url}">
                        <input type="hidden" name="token" value="{$token}">
                        <input type="hidden" name="clv_generate_backup_codes" value="1">
                        <button type="submit" class="clv-btn-sm clv-btn-primary" onclick="return confirm('{$lang.generate_codes_warn|default:'Generating new backup codes will invalidate any existing ones.'}');">
                            🔑 {$lang.generate_codes|default:'Generate New Backup Codes'}
                        </button>
                    </form>
                {/if}
            </div>

            <!-- Trusted Devices Section -->
            <div class="clv-security-section" style="margin-top:24px;">
                <h3 class="clv-section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                    {$lang.trusted_devices_title|default:'Trusted Devices'}
                </h3>

                {if $client_devices && count($client_devices) > 0}
                    <div class="clv-devices-list">
                        {foreach from=$client_devices item=dev}
                            <div class="clv-device-item">
                                <div class="clv-device-info">
                                    <div class="clv-device-name">
                                        <strong>{$dev->user_agent|truncate:35:"..."|default:'Browser Session'}</strong>
                                    </div>
                                    <div class="clv-device-meta">
                                        <span>IP: {$dev->ip_address|default:'Unknown'}</span> &middot; 
                                        <span>Expires: {$dev->expires_at|truncate:10:""}</span>
                                    </div>
                                </div>
                                <form method="post" action="{$devices_url}&action=revokedevice&device_id={$dev->id}">
                                    <input type="hidden" name="token" value="{$token}">
                                    <button type="submit" class="clv-btn-sm clv-btn-danger" onclick="return confirm('Revoke this device?');">{$lang.revoke|default:'Revoke'}</button>
                                </form>
                            </div>
                        {/foreach}
                    </div>
                    <form method="post" action="{$devices_url}&action=revokealldevices" style="margin-top:10px;">
                        <input type="hidden" name="token" value="{$token}">
                        <button type="submit" class="clv-btn-sm clv-btn-secondary" onclick="return confirm('Revoke all trusted devices?');">{$lang.revoke_all|default:'Revoke All Devices'}</button>
                    </form>
                {else}
                    <p style="color:var(--clv-text-muted);font-size:13px;margin:8px 0;text-align:left;">{$lang.no_trusted_devices|default:'You currently have no trusted devices.'}</p>
                {/if}
            </div>

            <p class="clv-logout" style="margin-top:22px;">
                <a href="{$back_url|default:'clientarea.php'}">&larr; Back to Client Area</a>
            </p>

        {else}
            <div class="clv-icon-wrapper clv-icon-shield">
                <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    <rect x="9" y="11" width="6" height="5" rx="1"></rect>
                    <path d="M10 11V9a2 2 0 1 1 4 0v2"></path>
                </svg>
            </div>
            <h2 class="clv-title">{$lang.title}</h2>
            <p class="clv-text">{if $view_mode == 'backup'}{$lang.backup_code_label|default:'Enter your emergency backup recovery code'}{else}{$lang.instruction}{/if}</p>

            {if $error}
                <div class="clv-alert clv-alert-error">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span>{$error}</span>
                </div>
            {/if}
            {if $info}
                <div class="clv-alert clv-alert-info">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="16" x2="12" y2="12"></line>
                        <line x1="12" y1="8" x2="12.01" y2="8"></line>
                    </svg>
                    <span>{$info}</span>
                </div>
            {/if}

            {if $view_mode == 'backup'}
                <!-- Emergency Backup Code Form -->
                <form method="post" action="{$backup_url}" class="clv-form" id="clv_backup_form" autocomplete="off">
                    <input type="hidden" name="token" value="{$token}">
                    <label for="clv_backup_code" class="clv-label">{$lang.backup_code_label|default:'Emergency Backup Code'}</label>
                    <div class="clv-input-wrapper">
                        <input type="text"
                               id="clv_backup_code"
                               name="clv_backup_code"
                               class="clv-single-input"
                               maxlength="16"
                               autocomplete="off"
                               placeholder="{$lang.backup_code_placeholder|default:'Enter 8-digit backup code'}"
                               required
                               autofocus>
                    </div>

                    {if $remember_device_enabled}
                        <div class="clv-remember-wrapper">
                            <label class="clv-checkbox-label">
                                <input type="checkbox" name="trust_device" value="on" checked>
                                <span>{$remember_label}</span>
                            </label>
                        </div>
                    {/if}

                    <button type="submit" id="clv_backup_btn" class="clv-btn">
                        <span>{$lang.submit}</span>
                    </button>
                </form>

                <p class="clv-toggle-mode">
                    <a href="{$otp_mode_url}">&larr; {$lang.use_email_otp|default:'Use email verification code'}</a>
                </p>

            {else}
                <!-- Standard PIN-Style 6-Box OTP Form -->
                <form method="post" action="{$verify_url}" class="clv-form" id="clv_verify_form" autocomplete="off">
                    <input type="hidden" name="token" value="{$token}">
                    <input type="hidden" id="clv_code" name="clv_code" value="">
                    
                    <label class="clv-label">{$lang.code_label}</label>
                    
                    <div class="clv-pin-container" id="clv_pin_container">
                        {for $i=0 to ($otp_length-1)}
                            <input type="text"
                                   class="clv-pin-box"
                                   inputmode="numeric"
                                   pattern="[0-9]*"
                                   maxlength="1"
                                   autocomplete="off"
                                   data-index="{$i}"
                                   {if $i==0}autofocus{/if}>
                        {/for}
                    </div>

                    {if $remember_device_enabled}
                        <div class="clv-remember-wrapper">
                            <label class="clv-checkbox-label">
                                <input type="checkbox" name="trust_device" value="on" checked>
                                <span>{$remember_label}</span>
                            </label>
                        </div>
                    {/if}

                    <button type="submit" id="clv_submit_btn" class="clv-btn">
                        <span id="clv_submit_text">{$lang.submit}</span>
                    </button>
                </form>

                <form method="post" action="{$resend_url}" class="clv-resend-form" id="clv_resend_form">
                    <input type="hidden" name="token" value="{$token}">
                    <button type="submit" id="clv_resend_btn" class="clv-resend-btn" {if $cooldown_remaining > 0}disabled{/if}>
                        <span id="clv_resend_text">
                            {if $cooldown_remaining > 0}
                                {$lang.resend_in|default:'Resend code in'} {$cooldown_remaining}{$lang.seconds|default:'s'}
                            {else}
                                {$lang.resend}
                            {/if}
                        </span>
                    </button>
                </form>

                <p class="clv-toggle-mode">
                    <a href="{$backup_url}">{$lang.use_backup_code|default:'Use a backup recovery code'}</a>
                </p>
            {/if}

            <p class="clv-logout">
                <a href="{$logout_url|default:'logout.php'}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    {$lang.cancel}
                </a>
            </p>
        {/if}
    </div>
</div>

<style>{literal}
/* ======================================================================
 * 1. Default Light Mode Theme Variables
 * ==================================================================== */
:root,
.clv-verify-container {
    --clv-card-bg: #ffffff;
    --clv-card-border: #e2e8f0;
    --clv-card-shadow: 0 10px 30px rgba(18, 38, 63, 0.08);
    --clv-text-primary: #1a2b4a;
    --clv-text-secondary: #5a6b85;
    --clv-text-muted: #8a97a8;
    --clv-input-bg: #f8fafc;
    --clv-input-border: #cbd5e1;
    --clv-input-text: #1a2b4a;
    --clv-input-focus: #2f6df6;
    --clv-input-focus-shadow: rgba(47, 109, 246, 0.15);
    --clv-btn-primary: #2f6df6;
    --clv-btn-hover: #2055cb;
    --clv-btn-secondary: #f1f5f9;
    --clv-btn-secondary-text: #334155;
    --clv-alert-err-bg: #fdecea;
    --clv-alert-err-border: #f5c6cb;
    --clv-alert-err-text: #b71c1c;
    --clv-alert-info-bg: #e8f4fd;
    --clv-alert-info-border: #b8e0ff;
    --clv-alert-info-text: #0b62a8;
    --clv-divider: #edf2f7;
    --clv-section-bg: #f8fafc;
    --clv-pill-bg: #ffffff;
}

/* ======================================================================
 * 2. Explicit WHMCS / Lagom Dark Mode Theme Overrides
 * ==================================================================== */
html[data-theme="dark"],
html[data-bs-theme="dark"],
html[data-style="dark"],
html.theme-dark,
html.dark,
html.dark-mode,
html.site-theme-dark,
html.lagom-theme-dark,
html.mode-dark,
body[data-theme="dark"],
body[data-bs-theme="dark"],
body[data-style="dark"],
body.theme-dark,
body.dark,
body.dark-mode,
body.site-theme-dark,
body.lagom-theme-dark,
body.mode-dark,
body.theme-default.theme-dark,
.clv-verify-container.clv-dark-theme,
.theme-dark .clv-verify-container,
[data-theme="dark"] .clv-verify-container,
[data-bs-theme="dark"] .clv-verify-container,
.dark-mode .clv-verify-container,
.dark .clv-verify-container {
    --clv-card-bg: #1c2230 !important;
    --clv-card-border: #2e384d !important;
    --clv-card-shadow: 0 16px 40px rgba(0, 0, 0, 0.5) !important;
    --clv-text-primary: #f8fafc !important;
    --clv-text-secondary: #94a3b8 !important;
    --clv-text-muted: #64748b !important;
    --clv-input-bg: #131722 !important;
    --clv-input-border: #334155 !important;
    --clv-input-text: #ffffff !important;
    --clv-input-focus: #3b82f6 !important;
    --clv-input-focus-shadow: rgba(59, 130, 246, 0.25) !important;
    --clv-btn-secondary: #2e384d !important;
    --clv-btn-secondary-text: #e2e8f0 !important;
    --clv-alert-err-bg: rgba(183, 28, 28, 0.2) !important;
    --clv-alert-err-border: rgba(183, 28, 28, 0.4) !important;
    --clv-alert-err-text: #fca5a5 !important;
    --clv-alert-info-bg: rgba(11, 98, 168, 0.2) !important;
    --clv-alert-info-border: rgba(11, 98, 168, 0.4) !important;
    --clv-alert-info-text: #93c5fd !important;
    --clv-divider: #2e384d !important;
    --clv-section-bg: #131722 !important;
    --clv-pill-bg: #1c2230 !important;
}

/* ======================================================================
 * 3. Component Styles (Strictly using CSS variables)
 * ==================================================================== */
.clv-verify-container{display:flex;justify-content:center;align-items:flex-start;padding:40px 15px;min-height:50vh;}
.clv-card{background:var(--clv-card-bg);border:1px solid var(--clv-card-border);border-radius:16px;box-shadow:var(--clv-card-shadow);max-width:460px;width:100%;padding:38px 34px;text-align:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;transition:background .2s,border-color .2s,box-shadow .2s;}
.clv-icon-wrapper{display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:50%;margin-bottom:18px;}
.clv-icon-shield{background:rgba(47,109,246,0.12);color:var(--clv-btn-primary);}
.clv-icon-success{background:rgba(30,126,52,0.12);color:#22c55e;}
.clv-title{margin:0 0 10px;font-size:23px;font-weight:700;color:var(--clv-text-primary);}
.clv-text{margin:0 0 22px;color:var(--clv-text-secondary);font-size:14px;line-height:1.55;}
.clv-alert{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:8px;font-size:13.5px;margin-bottom:18px;text-align:left;}
.clv-alert-error{background:var(--clv-alert-err-bg);color:var(--clv-alert-err-text);border:1px solid var(--clv-alert-err-border);}
.clv-alert-info{background:var(--clv-alert-info-bg);color:var(--clv-alert-info-text);border:1px solid var(--clv-alert-info-border);}
.clv-label{display:block;font-size:13.5px;color:var(--clv-text-primary);margin-bottom:10px;font-weight:600;text-align:left;}

/* PIN-Style 6-Box Inputs */
.clv-pin-container{display:flex;justify-content:space-between;gap:8px;margin-bottom:18px;}
.clv-pin-box{flex:1;min-width:0;height:56px;font-size:24px;font-weight:700;text-align:center;color:var(--clv-input-text);background:var(--clv-input-bg);border:2px solid var(--clv-input-border);border-radius:10px;outline:none;transition:border-color .15s,box-shadow .15s,background .15s;}
.clv-pin-box:focus{background:var(--clv-card-bg);border-color:var(--clv-input-focus);box-shadow:0 0 0 3px var(--clv-input-focus-shadow);}
.clv-single-input{width:100%;box-sizing:border-box;padding:13px 14px;font-size:18px;font-weight:600;letter-spacing:2px;text-align:center;color:var(--clv-input-text);background:var(--clv-input-bg);border:2px solid var(--clv-input-border);border-radius:10px;outline:none;margin-bottom:16px;transition:border-color .15s,box-shadow .15s;}
.clv-single-input:focus{background:var(--clv-card-bg);border-color:var(--clv-input-focus);box-shadow:0 0 0 3px var(--clv-input-focus-shadow);}

.clv-remember-wrapper{margin:12px 0 20px;text-align:left;}
.clv-checkbox-label{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--clv-text-secondary);cursor:pointer;user-select:none;}
.clv-checkbox-label input[type="checkbox"]{width:16px;height:16px;cursor:pointer;accent-color:var(--clv-btn-primary);}
.clv-btn{display:flex;align-items:center;justify-content:center;width:100%;box-sizing:border-box;padding:13px;font-size:15px;font-weight:600;color:#fff;background:var(--clv-btn-primary);border:none;border-radius:10px;cursor:pointer;text-decoration:none;transition:background .2s,transform .1s;}
.clv-btn:hover{background:var(--clv-btn-hover);color:#fff;}
.clv-btn:active{transform:scale(0.99);}
.clv-btn:disabled{opacity:0.6;cursor:not-allowed;}
.clv-btn-secondary{background:var(--clv-btn-secondary);color:var(--clv-btn-secondary-text);}
.clv-btn-secondary:hover{background:var(--clv-divider);color:var(--clv-btn-secondary-text);}
.clv-btn-sm{padding:6px 12px;font-size:12.5px;font-weight:600;border-radius:6px;border:none;cursor:pointer;text-decoration:none;}
.clv-btn-primary{background:var(--clv-btn-primary);color:#fff;}
.clv-btn-primary:hover{background:var(--clv-btn-hover);color:#fff;}
.clv-btn-danger{background:#dc2626;color:#fff;}
.clv-btn-danger:hover{background:#b91c1c;}
.clv-resend-form{margin:14px 0 0;}
.clv-resend-btn{background:none;border:none;color:var(--clv-btn-primary);font-size:13.5px;font-weight:500;cursor:pointer;padding:4px 8px;border-radius:6px;transition:color .15s;}
.clv-resend-btn:hover:not(:disabled){text-decoration:underline;}
.clv-resend-btn:disabled{color:var(--clv-text-muted);cursor:not-allowed;text-decoration:none;}
.clv-toggle-mode{margin:14px 0 0;font-size:13px;}
.clv-toggle-mode a{color:var(--clv-text-secondary);text-decoration:none;}
.clv-toggle-mode a:hover{color:var(--clv-btn-primary);text-decoration:underline;}
.clv-logout{margin:18px 0 0;padding-top:14px;border-top:1px solid var(--clv-divider);}
.clv-logout a{color:var(--clv-text-muted);font-size:12.5px;text-decoration:none;transition:color .15s;}
.clv-logout a:hover{color:var(--clv-text-primary);text-decoration:underline;}

/* Devices & Backup Codes List */
.clv-security-section{text-align:left;background:var(--clv-section-bg);border:1px solid var(--clv-card-border);border-radius:10px;padding:16px;margin-top:16px;}
.clv-section-title{margin:0 0 8px;font-size:15px;font-weight:700;color:var(--clv-text-primary);display:flex;align-items:center;gap:8px;}
.clv-backup-codes-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin:12px 0;}
.clv-backup-code-pill{background:var(--clv-pill-bg);border:1px solid var(--clv-card-border);border-radius:6px;padding:8px 12px;text-align:center;}
.clv-backup-code-pill code{font-size:15px;font-weight:700;letter-spacing:2px;color:var(--clv-text-primary);}
.clv-devices-list{margin:12px 0 0;text-align:left;}
.clv-device-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;background:var(--clv-pill-bg);border:1px solid var(--clv-card-border);border-radius:8px;margin-bottom:8px;}
.clv-device-name{font-size:13px;color:var(--clv-text-primary);display:flex;align-items:center;gap:6px;}
.clv-device-meta{font-size:11.5px;color:var(--clv-text-muted);margin-top:2px;}
{/literal}</style>

<script>{literal}
function clvGetBackupCodesText() {
    var box = document.getElementById('clv_backup_codes_box');
    if (!box) return '';
    var codes = [];
    var pills = box.querySelectorAll('code');
    pills.forEach(function(p) { codes.push(p.textContent.trim()); });
    return "=== YOUR 2FA EMERGENCY BACKUP CODES ===\n\n" + codes.join("\n") + "\n\n* Each code can only be used once.\n* Keep these codes secure.";
}

function clvCopyBackupCodes() {
    var txt = clvGetBackupCodesText();
    if (!txt) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(function() {
            alert('{/literal}{$lang.codes_copied|default:"Backup codes copied to clipboard!"}{literal}');
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = txt;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        alert('{/literal}{$lang.codes_copied|default:"Backup codes copied to clipboard!"}{literal}');
    }
}

function clvDownloadBackupCodes() {
    var txt = clvGetBackupCodesText();
    if (!txt) return;
    var blob = new Blob([txt], { type: 'text/plain;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = '2fa-backup-codes.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function clvPrintBackupCodes() {
    var txt = clvGetBackupCodesText();
    if (!txt) return;
    var win = window.open('', 'PRINT', 'height=400,width=600');
    win.document.write('<html><head><title>2FA Backup Codes</title></head><body><pre style="font-size:16px;line-height:1.6;">' + txt + '</pre></body></html>');
    win.document.close();
    win.focus();
    win.print();
    win.close();
}

/* Dynamic WHMCS & Lagom Dark/Light Mode Live Detection & Sync */
function clvSyncTheme() {
    var container = document.getElementById('clv_container');
    if (!container) return;

    var isDark = false;
    var html = document.documentElement;
    var body = document.body;

    var htmlTheme = (html.getAttribute('data-theme') || html.getAttribute('data-bs-theme') || html.getAttribute('data-style') || '').toLowerCase();
    var bodyTheme = (body ? (body.getAttribute('data-theme') || body.getAttribute('data-bs-theme') || body.getAttribute('data-style') || '') : '').toLowerCase();

    if (htmlTheme === 'dark' || bodyTheme === 'dark') {
        isDark = true;
    } else if (htmlTheme === 'light' || bodyTheme === 'light') {
        isDark = false;
    } else {
        var darkClasses = ['theme-dark', 'dark', 'dark-mode', 'mode-dark', 'site-theme-dark', 'lagom-theme-dark'];
        for (var i = 0; i < darkClasses.length; i++) {
            if (html.classList.contains(darkClasses[i]) || (body && body.classList.contains(darkClasses[i]))) {
                isDark = true;
                break;
            }
        }
    }

    if (!isDark && htmlTheme !== 'light' && bodyTheme !== 'light') {
        try {
            var lsStyle = (localStorage.getItem('lagom-theme-style') || localStorage.getItem('theme') || localStorage.getItem('theme-mode') || localStorage.getItem('site-theme') || '').toLowerCase();
            if (lsStyle === 'dark') {
                isDark = true;
            }
        } catch(e) {}
    }

    if (!isDark && body && htmlTheme !== 'light' && bodyTheme !== 'light') {
        try {
            var bg = window.getComputedStyle(body).backgroundColor;
            var rgb = bg.match(/\d+/g);
            if (rgb && rgb.length >= 3) {
                var r = parseInt(rgb[0], 10), g = parseInt(rgb[1], 10), b = parseInt(rgb[2], 10), a = rgb[3] !== undefined ? parseFloat(rgb[3]) : 1;
                if (a > 0.5 && (r < 75 && g < 75 && b < 75)) {
                    isDark = true;
                }
            }
        } catch(e) {}
    }

    if (isDark) {
        container.classList.add('clv-dark-theme');
    } else {
        container.classList.remove('clv-dark-theme');
    }
}

clvSyncTheme();

if (window.MutationObserver) {
    var observer = new MutationObserver(function() {
        clvSyncTheme();
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme', 'data-style'] });
    if (document.body) {
        observer.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme', 'data-style', 'style'] });
    }
}

document.addEventListener('click', function(e) {
    setTimeout(clvSyncTheme, 50);
    setTimeout(clvSyncTheme, 250);
});
window.addEventListener('storage', clvSyncTheme);

(function(){
    var container = document.getElementById('clv_pin_container');
    var hiddenInput = document.getElementById('clv_code');
    var form = document.getElementById('clv_verify_form');
    var btn = document.getElementById('clv_submit_btn');
    var resendBtn = document.getElementById('clv_resend_btn');
    var resendText = document.getElementById('clv_resend_text');
    var cooldown = parseInt('{/literal}{$cooldown_remaining|default:0}{literal}', 10) || 0;
    var resendReadyText = '{/literal}{$lang.resend|default:"Resend Code"}{literal}';
    var resendInText = '{/literal}{$lang.resend_in|default:"Resend code in"}{literal}';
    var secText = '{/literal}{$lang.seconds|default:"s"}{literal}';

    if (container && hiddenInput) {
        var boxes = container.querySelectorAll('.clv-pin-box');
        
        function syncPin() {
            var val = '';
            for (var i = 0; i < boxes.length; i++) {
                val += boxes[i].value;
            }
            hiddenInput.value = val;
        }

        boxes.forEach(function(box, index) {
            box.addEventListener('input', function(e) {
                var digit = box.value.replace(/\D/g, '');
                box.value = digit.slice(0, 1);
                syncPin();

                if (box.value && index < boxes.length - 1) {
                    boxes[index + 1].focus();
                }
            });

            box.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace') {
                    if (!box.value && index > 0) {
                        boxes[index - 1].focus();
                        boxes[index - 1].value = '';
                        syncPin();
                        e.preventDefault();
                    }
                } else if (e.key === 'ArrowLeft' && index > 0) {
                    boxes[index - 1].focus();
                } else if (e.key === 'ArrowRight' && index < boxes.length - 1) {
                    boxes[index + 1].focus();
                }
            });

            box.addEventListener('paste', function(e) {
                e.preventDefault();
                var pastedData = (e.clipboardData || window.clipboardData).getData('text');
                var digits = pastedData.replace(/\D/g, '');
                if (digits) {
                    for (var i = 0; i < boxes.length; i++) {
                        boxes[i].value = digits[i] || '';
                    }
                    syncPin();
                    var focusIdx = Math.min(digits.length, boxes.length - 1);
                    boxes[focusIdx].focus();
                }
            });
        });
    }

    if (form && btn) {
        form.addEventListener('submit', function() {
            btn.disabled = true;
            var textSpan = document.getElementById('clv_submit_text');
            if (textSpan) {
                textSpan.textContent = 'Verifying...';
            }
        });
    }

    var backupForm = document.getElementById('clv_backup_form');
    var backupBtn = document.getElementById('clv_backup_btn');
    if (backupForm && backupBtn) {
        backupForm.addEventListener('submit', function() {
            backupBtn.disabled = true;
        });
    }

    if (cooldown > 0 && resendBtn && resendText) {
        resendBtn.disabled = true;
        var timer = setInterval(function() {
            cooldown--;
            if (cooldown <= 0) {
                clearInterval(timer);
                resendBtn.disabled = false;
                resendText.textContent = resendReadyText;
            } else {
                resendText.textContent = resendInText + ' ' + cooldown + secText;
            }
        }, 1000);
    }
})();
{/literal}</script>
