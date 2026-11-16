{* PANDI EPP registrar module for WHMCS - SPDX-License-Identifier: GPL-3.0-or-later *}
<div class="card">
    <div class="card-body">
        <div class="alert alert-block alert-info">
            <p>{$LANG.domainname}: <strong>{$domain|escape:'html'}</strong></p>
        </div>

        {if $successMessage}
            <div class="alert alert-success">{$successMessage|escape:'html'}</div>
        {/if}
        {if $errorMessage}
            <div class="alert alert-danger">{$errorMessage|escape:'html'}</div>
        {/if}

        {if !$dnssecSupported}
            <p class="text-center text-muted">The registry does not offer DNSSEC (RFC 5910) for this domain.</p>
        {else}
            <h3 class="card-title">DS records</h3>

            {if $keyDataInterface}
                <div class="alert alert-warning">The registry holds DNSKEY data for this domain, which can not be managed here.</div>
            {/if}

            {if $DSRecords eq 'YES'}
                {foreach $DSRecordslist as $item}
                    <form method="post" action="clientarea.php">
                        <input type="hidden" name="token" value="{$token}" />
                        <input type="hidden" name="action" value="domaindetails" />
                        <input type="hidden" name="id" value="{$domainid|escape:'html'}" />
                        <input type="hidden" name="modop" value="custom" />
                        <input type="hidden" name="a" value="manageDNSSECDSRecords" />
                        <input type="hidden" name="command" value="secDNSrem" />
                        <input type="hidden" name="keyTag" value="{$item.keyTag|escape:'html'}" />
                        <input type="hidden" name="alg" value="{$item.alg|escape:'html'}" />
                        <input type="hidden" name="digestType" value="{$item.digestType|escape:'html'}" />
                        <input type="hidden" name="digest" value="{$item.digest|escape:'html'}" />

                        <table class="table table-sm">
                            <tr><th>Key tag</th><td>{$item.keyTag|escape:'html'}</td></tr>
                            <tr><th>Algorithm</th><td>{$item.alg|escape:'html'}</td></tr>
                            <tr><th>Digest type</th><td>{$item.digestType|escape:'html'}</td></tr>
                            <tr><th>Digest</th><td style="word-break: break-all;"><code>{$item.digest|escape:'html'}</code></td></tr>
                        </table>
                        <div class="text-center">
                            <input type="submit" class="btn btn-danger" value="Remove DS record" />
                        </div>
                    </form>
                    <hr>
                {/foreach}
            {else}
                <p class="text-center text-muted">No DS records are published for this domain.</p>
            {/if}

            <h3 class="card-title">Add a DS record</h3>
            <form method="post" action="clientarea.php">
                <input type="hidden" name="token" value="{$token}" />
                <input type="hidden" name="action" value="domaindetails" />
                <input type="hidden" name="id" value="{$domainid|escape:'html'}" />
                <input type="hidden" name="modop" value="custom" />
                <input type="hidden" name="a" value="manageDNSSECDSRecords" />
                <input type="hidden" name="command" value="secDNSadd" />

                <div class="form-group">
                    <label for="dsKeyTag">Key tag</label>
                    <input id="dsKeyTag" name="keyTag" type="number" min="0" max="65535" class="form-control" required />
                </div>
                <div class="form-group">
                    <label for="dsAlg">Algorithm</label>
                    <select id="dsAlg" name="alg" class="form-control" required>
                        {foreach $algorithms as $number => $label}
                            <option value="{$number}">{$number} - {$label|escape:'html'}</option>
                        {/foreach}
                    </select>
                </div>
                <div class="form-group">
                    <label for="dsDigestType">Digest type</label>
                    <select id="dsDigestType" name="digestType" class="form-control" required>
                        {foreach $digestTypes as $number => $label}
                            <option value="{$number}">{$number} - {$label|escape:'html'}</option>
                        {/foreach}
                    </select>
                </div>
                <div class="form-group">
                    <label for="dsDigest">Digest (hexadecimal)</label>
                    <input id="dsDigest" type="text" name="digest" class="form-control" pattern="[0-9A-Fa-f\s]+" required />
                </div>

                <p class="text-center">
                    <input type="submit" class="btn btn-primary" value="Add DS record" />
                </p>
            </form>
        {/if}
    </div>
</div>
