{if $ivyEnabled}
<div class="ivy--express-checkout-btn {if $addDivClass}{$addDivClass}{/if}"
     data-action="{url controller=IvyExpress action=start expres='true'}"
     data-refresh="{url controller=IvyExpress action=refresh}"
     {if $ivyAddToCart}data-add-to-ivycart="true"{/if}>
    <div
        class="ivy-checkout-button {if $addButtonClass}{$addButtonClass}{/if}"
        style="visibility: hidden;"
        data-cart-value="{$iviPrice|replace:',':'.'}"
        data-shop-category="{$ivyMcc}"
        data-locale="{$ivyLocale}"
        data-currency-code="{$currency}"
        data-theme = "{$theme}"
    ></div>
    <template data-src="{$ivyButtonUrl}"></template>
</div>
{/if}