{* PANDI EPP registrar module for WHMCS - SPDX-License-Identifier: GPL-3.0-or-later *}
<div class="card">
    <div class="card-body">
        <h3 class="card-title">Child nameservers of <span class="text-primary">{$domain|escape:'html'}</span></h3>

        {if $errorMessage}
            <div class="alert alert-danger">{$errorMessage|escape:'html'}</div>
        {elseif $HostRecords eq 'YES'}
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Nameserver</th>
                        <th scope="col">IP address</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $HostRecordsList as $index => $item}
                        <tr>
                            <th scope="row">{$index+1}</th>
                            <td>{$item.name|escape:'html'}</td>
                            <td>{$item.ip|escape:'html'}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {else}
            <p class="text-center text-muted">This domain has no child nameservers.</p>
        {/if}
    </div>
</div>
