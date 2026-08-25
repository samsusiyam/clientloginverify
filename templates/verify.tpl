<div class="clv-verify-container">
    <div class="clv-card">
        {if $normalview}
            <div class="clv-icon-wrapper clv-icon-success">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    <polyline points="9 12 11 14 15 10"></polyline>
                </svg>
            </div>
            <h2 class="clv-title">{$lang.title}</h2>
            <p class="clv-text">{$lang.normal_active}</p>
            <a href="{$logout_url|default:'clientarea.php'}" class="clv-btn">{$lang.continue}</a>
        {else}
            <div class="clv-icon-wrapper clv-icon-shield">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    <rect x="9" y="11" width="6" height="5" rx="1"></rect>
                    <path d="M10 11V9a2 2 0 1 1 4 0v2"></path>
                </svg>
            </div>
            <h2 class="clv-title">{$lang.title}</h2>
            <p class="clv-text">{$lang.instruction}</p>

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

            <form method="post" action="{$verify_url}" class="clv-form" id="clv_verify_form" autocomplete="off">
                <input type="hidden" name="token" value="{$token}">
                <label for="clv_code" class="clv-label">{$lang.code_label}</label>
                <div class="clv-input-wrapper">
                    <input type="text"
                           id="clv_code"
                           name="clv_code"
                           class="clv-input"
                           inputmode="numeric"
                           pattern="[0-9]*"
                           maxlength="{$otp_length}"
                           autocomplete="one-time-code"
                           placeholder="{$lang.placeholder|default:'Enter code'}"
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

                <button type="submit" id="clv_submit_btn" class="clv-btn">
                    <span class="clv-btn-text">{$lang.submit}</span>
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

            <p class="clv-logout">
                <a href="{$logout_url|default:'logout.php'}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:3px;">
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
.clv-verify-container{display:flex;justify-content:center;align-items:flex-start;padding:40px 15px;min-height:50vh;}
.clv-card{background:#ffffff;border:1px solid #e3e8ee;border-radius:14px;box-shadow:0 10px 30px rgba(18,38,63,.08);max-width:440px;width:100%;padding:36px 32px;text-align:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}
.clv-icon-wrapper{display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:50%;margin-bottom:18px;}
.clv-icon-shield{background:#eff4ff;color:#2f6df6;}
.clv-icon-success{background:#e6f4ea;color:#1e7e34;}
.clv-title{margin:0 0 10px;font-size:22px;font-weight:700;color:#1a2b4a;}
.clv-text{margin:0 0 22px;color:#5a6b85;font-size:14px;line-height:1.55;}
.clv-alert{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:8px;font-size:13.5px;margin-bottom:18px;text-align:left;}
.clv-alert-error{background:#fdecea;color:#b71c1c;border:1px solid #f5c6cb;}
.clv-alert-info{background:#e8f4fd;color:#0b62a8;border:1px solid #b8e0ff;}
.clv-label{display:block;font-size:13px;color:#1a2b4a;margin-bottom:8px;font-weight:600;text-align:left;}
.clv-input-wrapper{margin-bottom:14px;}
.clv-input{width:100%;box-sizing:border-box;padding:14px 12px;font-size:26px;font-weight:700;letter-spacing:10px;text-align:center;border:2px solid #d0d7e2;border-radius:10px;outline:none;background:#f8fafc;color:#1a2b4a;transition:border-color .2s,box-shadow .2s,background .2s;}
.clv-input:focus{background:#fff;border-color:#2f6df6;box-shadow:0 0 0 4px rgba(47,109,246,.15);}
.clv-remember-wrapper{margin:12px 0 18px;text-align:left;}
.clv-checkbox-label{display:flex;align-items:center;gap:8px;font-size:13px;color:#4a5568;cursor:pointer;user-select:none;}
.clv-checkbox-label input[type="checkbox"]{width:16px;height:16px;cursor:pointer;accent-color:#2f6df6;}
.clv-btn{display:flex;align-items:center;justify-content:center;width:100%;box-sizing:border-box;padding:13px;font-size:15px;font-weight:600;color:#fff;background:#2f6df6;border:none;border-radius:9px;cursor:pointer;text-decoration:none;transition:background .2s,transform .1s;}
.clv-btn:hover{background:#2055cb;color:#fff;}
.clv-btn:active{transform:scale(0.99);}
.clv-btn:disabled{background:#94a3b8;cursor:not-allowed;}
.clv-resend-form{margin:16px 0 0;}
.clv-resend-btn{background:none;border:none;color:#2f6df6;font-size:13.5px;font-weight:500;cursor:pointer;padding:6px 10px;border-radius:6px;transition:background .15s,color .15s;}
.clv-resend-btn:hover:not(:disabled){text-decoration:underline;}
.clv-resend-btn:disabled{color:#94a3b8;cursor:not-allowed;text-decoration:none;}
.clv-logout{margin:18px 0 0;padding-top:14px;border-top:1px solid #edf2f7;}
.clv-logout a{color:#718096;font-size:12.5px;text-decoration:none;transition:color .15s;}
.clv-logout a:hover{color:#2d3748;text-decoration:underline;}
{/literal}</style>

<script>{literal}
(function(){
    var input = document.getElementById('clv_code');
    var form = document.getElementById('clv_verify_form');
    var btn = document.getElementById('clv_submit_btn');
    var resendBtn = document.getElementById('clv_resend_btn');
    var resendText = document.getElementById('clv_resend_text');
    var cooldown = parseInt('{/literal}{$cooldown_remaining|default:0}{literal}', 10) || 0;
    var resendReadyText = '{/literal}{$lang.resend|default:"Resend Code"}{literal}';
    var resendInText = '{/literal}{$lang.resend_in|default:"Resend code in"}{literal}';
    var secText = '{/literal}{$lang.seconds|default:"s"}{literal}';

    if (input) {
        input.focus();
        input.addEventListener('input', function() {
            var cleaned = input.value.replace(/\D/g, '');
            if (cleaned !== input.value) {
                input.value = cleaned;
            }
        });
    }

    if (form && btn) {
        form.addEventListener('submit', function() {
            btn.disabled = true;
            btn.style.opacity = '0.75';
            btn.innerHTML = '<span class="clv-btn-text">Verifying...</span>';
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
