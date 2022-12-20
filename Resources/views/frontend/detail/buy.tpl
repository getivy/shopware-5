{extends file="parent:frontend/detail/buy.tpl"}

{block name="frontend_detail_buy"}
    {$smarty.block.parent}
    <div class="ivy-banner-wrapper">
    {if $darkThemeDetail}
        {$theme = 'dark'}
    {else}
        {$theme = 'light'}
    {/if}
    {include file="frontend/ivy_payment_plugin/banner.tpl"
        addDivClass='buybox--form'
        addButtonClass='buybox--button'
        iviPrice=$sArticle.price
        ivyAddToCart=true theme=$theme}
    </div>
    <div style="clear: both;"></div>
{/block}