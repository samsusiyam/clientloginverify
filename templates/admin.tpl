<img src="{$logo_url}" alt="Client Login Verify" style="max-height:64px;margin-bottom:12px;">
<h2>Client Login Verify</h2>
<p>Email-based two-factor authentication (2FA) for WHMCS client logins.</p>
<ul>
    <li><strong>Pending verifications:</strong> {$pending}</li>
    <li><strong>Total log entries:</strong> {$totalLogs}</li>
</ul>
<p>
    <a class="btn btn-default" href="{$modulelink}&view=clients">Client 2FA Status</a>
    <a class="btn btn-default" href="{$modulelink}&view=logs">View Logs</a>
    <a class="btn btn-primary" href="{$modulelink}&view=settings">Module Settings</a>
</p>
