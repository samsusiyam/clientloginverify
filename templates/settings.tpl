<img src="{$logo_url}" alt="Client Login Verify" style="max-height:48px;margin-bottom:12px;">
{if $view eq 'logs'}
    <h2>Verification Logs</h2>
    <p><a href="{$modulelink}">&laquo; Back</a></p>
    <table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">
        <thead>
            <tr>
                <th>Client ID</th>
                <th>Event</th>
                <th>IP</th>
                <th>Message</th>
                <th>Date/Time</th>
            </tr>
        </thead>
        <tbody>
        {foreach $logs as $log}
            <tr>
                <td>{$log->client_id|escape:'html'}</td>
                <td>{$log->event|escape:'html'}</td>
                <td>{$log->ip|escape:'html'}</td>
                <td>{$log->message|escape:'html'}</td>
                <td>{$log->created_at|escape:'html'}</td>
            </tr>
        {/foreach}
        </tbody>
    </table>
{else}
    <h2>Client 2FA Status</h2>
    <p><a href="{$modulelink}">&laquo; Back</a></p>
    <table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Group</th>
                <th>2FA</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        {foreach $clients as $c}
            <tr>
                <td>{$c->id}</td>
                <td>{$c->name|escape:'html'}</td>
                <td>{$c->groupid}</td>
                <td>{$c->effective} ({$c->current})</td>
                <td>
                    <a href="{$modulelink}&view=clients&action=setclient&client_id={$c->id}&val={$c->next}&token={$token}">{$c->label}</a>
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
{/if}
