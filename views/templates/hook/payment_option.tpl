<div class="plpayuponinvoice-info">
    {if isset($is_quotation_carrier) && $is_quotation_carrier}
        <p>
            {l s='Place your order now. You will receive a shipping quotation and an invoice by email. Payment is due within the term stated on the invoice.' mod='plpayuponinvoice'}
        </p>
    {else}
        <p>
            {l s='Place your order now and we will send you an invoice by email. Payment is due within the term stated on the invoice.' mod='plpayuponinvoice'}
        </p>
    {/if}
</div>
