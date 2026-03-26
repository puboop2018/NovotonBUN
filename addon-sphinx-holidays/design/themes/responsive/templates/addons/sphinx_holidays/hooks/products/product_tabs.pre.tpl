{*
 * Hook: products:product_tabs
 * Injects Sphinx booking form before product tabs on Sphinx hotel product pages
 *}

{if ($is_sphinx_hotel || $product.is_sphinx_hotel) && ($show_sphinx_booking_form || $product.show_sphinx_booking_form)}
    {$_sphinx_hotel_id = $sphinx_hotel_id|default:$product.sphinx_hotel_id}
    {$_sphinx_position = $sphinx_booking_form_position|default:$product.sphinx_booking_form_position|default:'before_tabs'}
    {if $_sphinx_position == 'before_tabs'}
        {include file="addons/sphinx_holidays/blocks/booking_engine.tpl"
            hotel_id=$_sphinx_hotel_id
            product_id=$product.product_id
        }
    {/if}
{/if}
