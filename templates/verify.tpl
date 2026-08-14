<div class="clv-verify">
    <div class="clv-card">
        {if $normalview}
            <div class="clv-icon">&#9989;</div>
            <h2 class="clv-title">{$lang.title}</h2>
            <p class="clv-text">{$lang.normal_active}</p>
            <a href="{$logout_url|default:'clientarea.php'}" class="clv-btn">{$lang.continue}</a>
        {else}
            <div class="clv-icon">&#128274;</div>
            <h2 class="clv-title">{$lang.title}</h2>
            <p class="clv-text">{$lang.instruction}</p>

            {if $error}
                <div class="clv-alert clv-alert-error">{$error}</div>
            {/if}
            {if $info}
                <div class="clv-alert clv-alert-info">{$info}</div>
            {/if}

            <form method="post" action="{$verify_url}" class="clv-form" autocomplete="off">
                <input type="hidden" name="token" value="{$token}">
                <label for="clv_code" class="clv-label">{$lang.code_label}</label>
                <input type="text"
                       id="clv_code"
                       name="clv_code"
                       class="clv-input"
                       inputmode="numeric"
                       pattern="[0-9]*"
                       maxlength="{$otp_length}"
                       autocomplete="one-time-code"
                       placeholder="Enter code"
                       required
                       autofocus>
                <button type="submit" class="clv-btn">{$lang.submit}</button>
            </form>

            <form method="post" action="{$resend_url}" class="clv-resend-form">
                <input type="hidden" name="token" value="{$token}">
                <button type="submit" class="clv-resend-btn">{$lang.resend}</button>
            </form>

            <p class="clv-logout"><a href="{$logout_url|default:'logout.php'}">{$lang.cancel}</a></p>
        {/if}
    </div>
</div>

<style>{literal}
.clv-verify{display:flex;justify-content:center;align-items:flex-start;padding:40px 15px;}
.clv-card{background:#fff;border:1px solid #e3e8ee;border-radius:12px;box-shadow:0 6px 28px rgba(20,40,80,.08);max-width:420px;width:100%;padding:34px 30px;text-align:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}
.clv-icon{font-size:44px;line-height:1;margin-bottom:6px;}
.clv-title{margin:6px 0 10px;font-size:22px;color:#1a2b4a;}
.clv-text{margin:0 0 22px;color:#5a6b85;font-size:14px;line-height:1.55;}
.clv-alert{padding:10px 13px;border-radius:6px;font-size:13px;margin-bottom:16px;text-align:left;}
.clv-alert-error{background:#fdecea;color:#b71c1c;border:1px solid #f5c6cb;}
.clv-alert-info{background:#e8f4fd;color:#0b62a8;border:1px solid #b8e0ff;}
.clv-label{display:block;font-size:13px;color:#1a2b4a;margin-bottom:6px;font-weight:600;text-align:left;}
.clv-input{width:100%;box-sizing:border-box;padding:13px 14px;font-size:24px;letter-spacing:10px;text-align:center;border:1px solid #cdd6e4;border-radius:8px;outline:none;transition:border-color .15s,box-shadow .15s;margin-bottom:16px;}
.clv-input:focus{border-color:#2f6df6;box-shadow:0 0 0 3px rgba(47,109,246,.15);}
.clv-btn{display:inline-block;width:100%;box-sizing:border-box;padding:12px;font-size:15px;font-weight:600;color:#fff;background:#2f6df6;border:none;border-radius:8px;cursor:pointer;text-decoration:none;transition:background .15s;}
.clv-btn:hover{background:#2257d6;color:#fff;}
.clv-resend-form{margin:16px 0 0;}
.clv-resend-btn{background:none;border:none;color:#2f6df6;font-size:13px;cursor:pointer;padding:0;}
.clv-resend-btn:hover{text-decoration:underline;}
.clv-logout{margin:14px 0 0;}
.clv-logout a{color:#8a97a8;font-size:12px;text-decoration:none;}
.clv-logout a:hover{text-decoration:underline;}
{/literal}</style>

<script>{literal}
(function(){
    var input=document.getElementById('clv_code');
    if(!input){return;}
    input.focus();
    input.addEventListener('input',function(){
        var cleaned=input.value.replace(/\D/g,'');
        if(cleaned!==input.value){input.value=cleaned;}
    });
    var max=parseInt(input.getAttribute('maxlength'),10)||6;
    input.addEventListener('keyup',function(){
        if(input.value.length===max){
            var form=input.closest('form');
            if(form){form.submit();}
        }
    });
})();
{/literal}</script>
