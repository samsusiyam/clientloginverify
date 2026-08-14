<div class="clv-verify">
    <div class="clv-card">
        {if $normalview}
            <div class="clv-logo">
                <span class="clv-shield">&#9989;</span>
            </div>
            <h2 class="clv-title">{$lang.title}</h2>
            <p class="clv-instruction">{$lang.normal_active}</p>
        {else}
            <div class="clv-logo">
                <span class="clv-shield">&#128274;</span>
            </div>
            <h2 class="clv-title">{$lang.title}</h2>
            <p class="clv-instruction">{$lang.instruction}</p>

            {if $error}
                <div class="clv-alert clv-alert-error">{$error}</div>
            {/if}
            {if $info}
                <div class="clv-alert clv-alert-info">{$info}</div>
            {/if}

            <form method="post" action="index.php?m=clientloginverify&clvverify=1" class="clv-form" autocomplete="off">
                <input type="hidden" name="token" value="{$token}">
                <div class="clv-field">
                    <label for="clv_otp">{$lang.code_label}</label>
                    <input type="text"
                           id="clv_otp"
                           name="clv_otp"
                           class="clv-input"
                           inputmode="numeric"
                           pattern="[0-9]*"
                           maxlength="{$otp_length}"
                           autocomplete="one-time-code"
                           placeholder="&bull;&bull;&bull;&bull;&bull;&bull;"
                           required
                           autofocus>
                </div>
                <button type="submit" class="clv-btn">{$lang.submit}</button>
            </form>

            <div class="clv-resend">
                <a href="index.php?m=clientloginverify&clvverify=1&action=resend&token={$token}">{$lang.resend}</a>
            </div>
        {/if}
    </div>
</div>

<style>
.clv-verify {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 40px 15px;
}
.clv-card {
    background: #fff;
    border: 1px solid #e3e8ee;
    border-radius: 10px;
    box-shadow: 0 4px 24px rgba(0,0,0,.06);
    max-width: 420px;
    width: 100%;
    padding: 32px 28px;
    text-align: center;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}
.clv-logo { margin-bottom: 8px; }
.clv-shield { font-size: 40px; line-height: 1; }
.clv-title { margin: 6px 0 8px; font-size: 22px; color: #1a2b4a; }
.clv-instruction { margin: 0 0 20px; color: #5a6b85; font-size: 14px; line-height: 1.5; }
.clv-alert { padding: 10px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
.clv-alert-error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
.clv-alert-info { background: #e8f4fd; color: #0b62a8; border: 1px solid #b8e0ff; }
.clv-field { text-align: left; margin-bottom: 16px; }
.clv-field label { display: block; font-size: 13px; color: #1a2b4a; margin-bottom: 6px; font-weight: 600; }
.clv-input {
    width: 100%;
    box-sizing: border-box;
    padding: 12px 14px;
    font-size: 22px;
    letter-spacing: 8px;
    text-align: center;
    border: 1px solid #cdd6e4;
    border-radius: 8px;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.clv-input:focus { border-color: #2f6df6; box-shadow: 0 0 0 3px rgba(47,109,246,.15); }
.clv-btn {
    width: 100%;
    padding: 12px;
    font-size: 15px;
    font-weight: 600;
    color: #fff;
    background: #2f6df6;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: background .15s;
}
.clv-btn:hover { background: #2257d6; }
.clv-resend { margin-top: 18px; font-size: 13px; }
.clv-resend a { color: #2f6df6; text-decoration: none; }
.clv-resend a:hover { text-decoration: underline; }
</style>
