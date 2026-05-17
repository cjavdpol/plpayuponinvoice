<div class="plpayuponinvoice-confirmation box">
    <h3 class="h3">{l s='Thank you for your order!' mod='plpayuponinvoice'}</h3>
    <p>
        {l s='Your order %s has been successfully placed.' sprintf=[$order_reference] mod='plpayuponinvoice'}
    </p>

    {if isset($is_quotation_carrier) && $is_quotation_carrier}
        <p>
            {l s='You have selected shipping on quotation. We will prepare a shipping quotation and send it together with your invoice within a few business days.' mod='plpayuponinvoice'}
        </p>
        <p>
            {l s='The invoice will include both the product amount and the shipping cost. Please pay within the payment term stated on the invoice.' mod='plpayuponinvoice'}
        </p>
    {else}
        <p>
            {l s='You will receive an invoice by email within a few business days. Please pay within the payment term stated on the invoice.' mod='plpayuponinvoice'}
        </p>
    {/if}

    <p>
        {l s='If you have any questions, feel free to contact us at %s.' sprintf=[$shop_email] mod='plpayuponinvoice'}
    </p>
</div>
