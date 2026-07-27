{script src="js/tygh/exceptions.js"}

{*
    Travel Core Template — hotel-style product detail layout.

    Derived from the stock blocks/product_templates/default_template.tpl
    (CS-Cart 4.20.1) with ONE structural change: the products:main_info_title
    hook region (product title + brand/features short list) renders as a
    full-width, left-aligned header ABOVE the image gallery instead of inside
    info column 1 — so the hotel name and the travel_core location line
    (hooks/products/main_info_title.post.tpl) lead the page, like a hotel
    booking site. Everything else is kept verbatim so core behaviour,
    captures and addon hooks stay intact. The hook appears exactly ONCE
    (moved, not duplicated) — a second occurrence would double every addon's
    title-area content.

    Selectable in Settings → Appearance → "Product detailed page view" as
    "Travel Core Template" (lang var: travelcore_template).

    Table of contents
    ---
    #10. Product details outer with tabs
    #20. Product details inner without tabs

    #90. Travel header: title + location, full width above the gallery

    #100. Product image

    #200. Product info
    #210. Product info columns wrapper

    #300. Product info. Column 1 (starts at options — title moved to #90)
    #330. Product options
    #340. Advanced product info
    #350. Product CODE
    #360. Product electronically distributed
    #370. Product features short table
    #380. Product short description

    #400. Product info. Column 2
    #410. Product offer
    #420. Product price container
    #430. Product price
    #440. Product list price (old_price)
    #450. Product price without taxes (clean_price) and You save (list_discount)
    #460. Product promo text
    #470. Product availability and quantity
    #480. Product availability
    #490. Product quantity
    #500. Product minimum quantity
    #510. Product buttons
    #520. Product buttons: Add to cart and addons
    #530. View full product details

    #550. Product bottom fixed

    #600. Product detail bottom (addons)
    #610. Product tabs in a popup window

    #700. Product tabs
*}

{* Product data *}
{$show_product_common_info = $show_product_common_info|default:true}
{$show_old_price_label = $show_old_price_label|default:false}
{$show_amount_label = $show_amount_label|default:false}
{$show_in_stock_label = $show_in_stock_label|default:false}
{$hide_qty_label = $hide_qty_label|default:true}
{$add_to_cart_type = $add_to_cart_type|default:"text"}
{$add_to_cart_class = $add_to_cart_class|default:"ty-products-buttons-block"}
{$show_product_company_data = $show_product_company_data|default:true}
{$show_product_company_data_in_advanced_options = $show_product_company_data_in_advanced_options|default:false}
{* /Product data *}

{* Default template *}
{$show_simple_product_images = $show_simple_product_images|default:false}
{$product_details_thumbnail_width = $product_details_thumbnail_width|default:$settings.Thumbnails.product_details_thumbnail_width}
{$product_details_thumbnail_width_int = $product_details_thumbnail_width|intval}
{$product_details_thumbnail_height = $product_details_thumbnail_height|default:$settings.Thumbnails.product_details_thumbnail_height}
{$product_img_wrapper_style = $product_img_wrapper_style|default:[
    "--ty-product-img-width"    => "`$product_details_thumbnail_width|default:$product_details_thumbnail_height`px",
    "width"                     => "var(--ty-product-img-width)"
]}
{$thumbnails_size = $thumbnails_size|default:68}
{$show_product_features_short_list = $show_product_features_short_list|default:true}
{$show_all_features_button = $show_all_features_button|default:true}
{$show_product_features_short_table = $show_product_features_short_table|default:true}
{$show_promo_text = $show_promo_text|default:true}
{$show_descr = $show_descr|default:true}
{$show_full_description_link = $show_full_description_link|default:true}
{$show_details_button = $show_details_button|default:false}
{$show_product_bottom_fixed = $show_product_bottom_fixed|default:true}
{$show_price_product_bottom_fixed = $show_price_product_bottom_fixed|default:true}
{$show_list_price_product_bottom_fixed = $show_list_price_product_bottom_fixed|default:true}
{$show_clean_price_product_bottom_fixed = $show_clean_price_product_bottom_fixed|default:true}
{* /Default template *}

{* #10. Product details outer with tabs *}
<div class="ty-product-block ty-product-detail">

    {* #20. Product details inner without tabs *}
    <div class="ty-product-block__wrapper" {if $product_details_thumbnail_width_int !== 0}style="--ty-product-block-image-width: {$product_details_thumbnail_width_int}px;"{/if}>

        {hook name="products:view_main_info"}

            {if $product}
                {$obj_id = $product.product_id}

                {* Get product data *}
                {include file="common/product_data.tpl"
                    product=$product
                }

                {* #90. Travel header: hotel name + location line (left) and
                   product code (right, same line — Unitheme-style), full
                   width above the gallery (.travel-pdp-header in
                   addons/travel_core/booking-pages.css). The location line is
                   contributed by travel_core's main_info_title POST hook.
                   The sku capture MOVED here from info column 1 — it carries
                   element ids, so it must render exactly once. *}
                <div class="travel-pdp-header">
                    <div class="travel-pdp-header__main">
                        {*
                            Addons:
                            - Product stars, reviews, and write a review (product_reviews)
                            - Product stars, reviews, and write a review (discussion)
                            - etc...
                        *}
                        {hook name="products:main_info_title"}
                            {* Product title *}
                            {if !$hide_title}
                                <h1 class="ty-product-block-title" {live_edit name="product:product:{$product.product_id}"}>
                                    <bdi>{$product.product nofilter}</bdi>
                                </h1>
                            {/if}
                            {* /Product title *}

                            {* Product brand and features short list *}
                            {hook name="products:brand"}
                                {hook name="products:brand_default"}
                                    {if $show_product_features_short_list}
                                        <div class="brand">
                                            {include file="views/products/components/product_features_short_list.tpl"
                                                features=$product.header_features
                                                feature_image=$feature_image
                                                product=$product
                                                image_size=$image_size
                                                no_container=$no_container
                                                settings=$settings
                                                smarty=$smarty
                                            }
                                        </div>
                                    {/if}
                                {/hook}
                            {/hook}
                            {* /Product brand and features short list *}
                        {/hook}
                    </div>

                    {* Product CODE (the stock #350 sku block, relocated) *}
                    <div class="travel-pdp-header__code ty-product-block__sku">
                        {$sku = "sku_`$obj_id`"}
                        {$smarty.capture.$sku nofilter}
                    </div>
                </div>
                {* /Travel header *}

                {* #95. Travel columns: OUR flex container owns the
                   image-left / info-right layout (.travel-pdp-columns in
                   booking-pages.css). The theme styles the wrapper's DIRECT
                   children — with the header injected as a new first child
                   the stock grid/float rules misplaced every sibling, so
                   the gallery and the info column move one level down where
                   only our flexbox applies. Core class names untouched
                   (previewer JS + capture contracts). *}
                <div class="travel-pdp-columns">

                {* #100. Product image *}
                <div
                    class="ty-product-block__img-wrapper"
                >
                    {hook name="products:image_wrap"}
                        {if !$no_images}
                            <div
                                class="ty-product-block__img cm-reload-{$product.product_id}"
                                data-ca-previewer="true"
                                id="product_images_{$product.product_id}_update"
                            >
                                {$product_labels = "product_labels_`$obj_prefix``$obj_id`"}
                                {* (Hooks) products:product_labels *}
                                {*
                                    Addons:
                                    - Save up to (master_products)
                                *}
                                {$smarty.capture.$product_labels nofilter}

                                {if $show_simple_product_images}
                                    <div class="ty-product-img ty-product-img--simple">
                                        {include file="common/image.tpl"
                                            image_width=$thumbnail_width
                                            image_height=$thumbnail_height
                                            images=$product.main_pair
                                            no_ids=true
                                        }
                                    </div>
                                {else}
                                    {include file="views/products/components/product_images.tpl"
                                        product=$product
                                        image_pair_var=$image_pair_var
                                        preview_id=$preview_id
                                        image_id=$image_id
                                        image_width=$product_details_thumbnail_width
                                        image_height=$product_details_thumbnail_height
                                        product_img_wrapper_style=$product_img_wrapper_style
                                        image_pair=$image_pair
                                        settings=$settings
                                        thumbnails_size=$thumbnails_size
                                        show_detailed_link=true
                                    }
                                {/if}
                            <!--product_images_{$product.product_id}_update--></div>
                        {/if}
                    {/hook}
                </div>
                {* /Product image *}

                {* #200. Product info *}
                {* ty-product-block__left - right block (backward compatibility) *}
                <div class="ty-product-block__left">

                    {$form_open = "form_open_`$obj_id`"}
                    {$smarty.capture.$form_open nofilter}

                        {* #210. Product info columns wrapper *}
                        <div class="ty-product-block__columns-wrapper">

                            {* #300. Product info. Column 1 (title lives in #90) *}
                            <div class="ty-product-block__col-1">

                                {* #330. Product options *}
                                <div class="ty-product-block__option">
                                    {$product_options = "product_options_`$obj_id`"}
                                    {* (Hooks) products:product_option_content *}
                                    {*
                                        Addons:
                                        - Product variation features as options (product_variations)
                                        - etc...
                                    *}
                                    {$smarty.capture.$product_options nofilter}
                                </div>
                                {* /Product options *}

                                {* #340. Advanced product info *}
                                <div class="ty-product-block__advanced-option clearfix">
                                    {$advanced_options = "advanced_options_`$obj_id`"}
                                    {* (Hooks) products:options_advanced *}
                                    {* Addons:
                                        - Return period (rma)
                                        - Supplier (suppliers)
                                        - Already bought (required_products)
                                        - Ask a question (vendor_communication)
                                        - etc...
                                    *}
                                    {$smarty.capture.$advanced_options nofilter}
                                </div>
                                {* /Advanced product info *}

                                {* #350. Product CODE — moved into the travel
                                   header (#90), right-aligned on the title line. *}

                                {* #360. Product electronically distributed *}
                                {$product_edp = "product_edp_`$obj_id`"}
                                {$smarty.capture.$product_edp nofilter}
                                {* /Product electronically distributed *}

                                {* #370. Product features short table *}
                                {if $product.product_features && $show_product_features_short_table}
                                    <div class="ty-product-block__product-features-short-table">
                                        <div class="ty-product-block__product-features-short-table-content">
                                            {include file="views/products/components/product_features_short_table.tpl"
                                                product_features=$product.product_features
                                                link_text_icon=$link_text_icon
                                                settings=$settings
                                            }
                                        </div>

                                        {if $show_all_features_button}
                                            {include file="buttons/button.tpl"
                                                but_text=__("all_features")
                                                but_role="text"
                                                but_external_click_id="features"
                                                but_scroll="features"
                                                but_meta="cm-external-click ty-btn__text"
                                            }
                                        {/if}
                                    </div>
                                {/if}
                                {* /Product features short table *}

                                {* #380. Product short description *}
                                {$prod_descr = "prod_descr_`$obj_id`"}
                                {if $show_descr && $smarty.capture.$prod_descr|strip_tags|trim}
                                    <div class="ty-product-block__description ty-product-block__description--default-template">
                                        <div class="ty-product-block__description-content ty-product-block__description-content--default-template">
                                            {$smarty.capture.$prod_descr nofilter}
                                        </div>

                                        {if $show_full_description_link}
                                            {include file="buttons/button.tpl"
                                                but_text=__("full_description")
                                                but_role="text"
                                                but_external_click_id="description"
                                                but_scroll="description"
                                                but_meta="cm-external-click ty-btn__text"
                                            }
                                        {/if}
                                    </div>
                                {/if}
                                {* /Product short description *}

                            </div>
                            {* /Product info. Column 1 *}

                            {* #400. Product info. Column 2 *}
                            <div class="ty-product-block__col-2">

                                {* #410. Product offer *}
                                <div class="ty-product-block__offer">

                                    {*
                                        Addons:
                                        - Shipping time and rates (geo_maps)
                                    *}
                                    {hook name="products:product_offer"}
                                        {* #420. Product price container *}
                                        {$old_price = "old_price_`$obj_id`"}
                                        {$price = "price_`$obj_id`"}
                                        {$clean_price = "clean_price_`$obj_id`"}
                                        {$list_discount = "list_discount_`$obj_id`"}
                                        {$discount_label = "discount_label_`$obj_id`"}
                                        <div
                                            class="price-wrap
                                                {if $smarty.capture.$old_price|trim
                                                    || $smarty.capture.$clean_price|trim
                                                    || $smarty.capture.$list_discount|trim
                                                }
                                                    prices-container
                                                {/if}"
                                        >
                                            {if $smarty.capture.$old_price|trim
                                                || $smarty.capture.$clean_price|trim
                                                || $smarty.capture.$list_discount|trim
                                            }
                                                <div class="ty-product-prices">
                                            {/if}

                                            {* #430. Product price *}
                                            {*
                                                Addons:
                                                - Price per unit (price_per_unit)
                                                - etc...
                                            *}
                                            {hook name="products:main_price"}
                                                {if $smarty.capture.$price|trim}
                                                    <div class="ty-product-block__price-actual">
                                                        {* (Hooks) products:prices_block *}
                                                        {*
                                                            Addons:
                                                            - Divido calculator (divido)
                                                            - And other offers (master_products)
                                                            - etc...
                                                        *}
                                                        {$smarty.capture.$price nofilter}
                                                    </div>
                                                {/if}
                                            {/hook}
                                            {* /Product price *}

                                            {* #440. Product list price (old_price) *}
                                            {if $smarty.capture.$old_price|trim
                                                || $smarty.capture.$clean_price|trim
                                                || $smarty.capture.$list_discount|trim
                                            }
                                                {* (Hooks) products:old_price *}
                                                {if $smarty.capture.$old_price|trim}{$smarty.capture.$old_price nofilter}{/if}
                                            {/if}
                                            {* /Product list price (old_price) *}

                                            {* #450. Product price without taxes (clean_price) and You save (list_discount) *}
                                            {if $smarty.capture.$old_price|trim
                                                || $smarty.capture.$clean_price|trim
                                                || $smarty.capture.$list_discount|trim
                                            }
                                                    {$smarty.capture.$clean_price nofilter}
                                                    {$smarty.capture.$list_discount nofilter}
                                                </div>
                                            {/if}
                                            {* /Product price without taxes (clean_price) and You save (list_discount) *}
                                        </div>
                                        {* /Product price container *}

                                        {* #460. Product promo text *}
                                        {hook name="products:promo_text"}
                                            {if $show_promo_text && $product.promo_text}
                                                <div class="ty-product-block__note-wrapper">
                                                    <div class="ty-product-block__note ty-product-block__note-inner">
                                                        {$product.promo_text nofilter}
                                                    </div>
                                                </div>
                                            {/if}
                                        {/hook}
                                        {* /Product promo text *}

                                        {* #470. Product availability and quantity *}
                                        <div class="ty-product-block__field-group">
                                            {* #480. Product availability *}
                                            {$product_amount = "product_amount_`$obj_id`"}
                                            {* (Hooks) products:product_amount *}
                                            {* Addons:
                                                - Price in points, Reward points (reward_points)
                                                - etc...
                                            *}
                                            {$smarty.capture.$product_amount nofilter}
                                            {* /Product availability *}

                                            {* #490. Product quantity *}
                                            {$qty = "qty_`$obj_id`"}
                                            {* (Hooks) products:qty *}
                                            {$smarty.capture.$qty nofilter}
                                            {* /Product quantity *}

                                            {* #500. Product minimum quantity *}
                                            {$min_qty = "min_qty_`$obj_id`"}
                                            {* (Hooks) products:qty_description *}
                                            {$smarty.capture.$min_qty nofilter}
                                            {* /Product minimum quantity *}
                                        </div>
                                        {* /Product availability and quantity *}

                                        {* #510. Product buttons *}
                                        <div class="ty-product-block__button">
                                            {* #520. Product buttons: Add to cart and addons *}
                                            {$add_to_cart = "add_to_cart_`$obj_id`"}
                                            {*
                                                (Hooks)
                                                products:add_to_cart
                                                products:add_to_cart_but_id
                                                products:buttons_block
                                                products:out_of_stock_block
                                                products:buy_now
                                            *}
                                            {*
                                                Addons:
                                                - Buy now with 1-click (call_requests)
                                                - etc
                                            *}
                                            {$smarty.capture.$add_to_cart nofilter}
                                            {* /Product buttons: Add to cart and addons *}

                                            {* #530. View full product details *}
                                            {if $show_details_button}
                                                {include file="buttons/button.tpl"
                                                    but_href="products.view?product_id=`$product.product_id`"
                                                    but_text=__("view_details")
                                                    but_role="submit"
                                                    but_meta="ty-product-block__view-details"
                                                }
                                            {/if}
                                            {* /View full product details *}
                                        </div>
                                        {* /Product buttons *}

                                        {* (Hooks) companies:product_company_data *}
                                        {* Addons:
                                            - Ask a question (vendor_communication)
                                        *}
                                        <div class="ty-product-block__product-company-data">
                                            {$product_company_data = "product_company_data_`$obj_id`"}
                                            {$smarty.capture.$product_company_data nofilter}
                                        </div>

                                    {/hook}

                                </div>
                                {* /Product offer *}

                            </div>
                            {* /Product info. Column 2 *}

                        </div>
                        {* /Product info columns wrapper *}

                        {* #550. Product bottom fixed *}
                        {include_ext file="views/products/components/product_bottom_fixed.tpl"
                            obj_id=$obj_id
                            show_product_bottom_fixed=$show_product_bottom_fixed
                            show_price=$show_price_product_bottom_fixed
                            show_list_price=$show_list_price_product_bottom_fixed
                            show_clean_price=$show_clean_price_product_bottom_fixed
                            show_add_to_cart=$show_add_to_cart_product_bottom_fixed
                            smarty=$smarty
                        }
                        {* /Product bottom fixed *}

                    {hook name="products:product_form_close_tag"}
                        {$form_close = "form_close_`$obj_id`"}
                        {$smarty.capture.$form_close nofilter}
                    {/hook}

                    {* #600. Product detail bottom (addons) *}
                    {*
                        Addons:
                        - Availability in stores (warehouses)
                    *}
                    {hook name="products:product_detail_bottom"}
                    {/hook}
                    {* /Product detail bottom (addons) *}

                    {* #610. Product tabs in a popup window *}
                    {if $show_product_tabs}
                        {include file="views/tabs/components/product_popup_tabs.tpl"
                            tabs=$tabs
                            smarty=$smarty
                        }
                        {$smarty.capture.popupsbox_content nofilter}
                    {/if}
                    {* /Product tabs in a popup window *}

                </div>
                {* /Product info *}

                </div>
                {* /Travel columns *}
            {/if}

        {/hook}
    </div>
    {* /Product details inner without tabs *}

    {if $smarty.capture.hide_form_changed == "Y"}
        {$hide_form = $smarty.capture.orig_val_hide_form}
    {/if}

    {* #700. Product tabs *}
    {if $show_product_tabs}
        {*
            Addons:
            - Product bundles before product description (product_bundles)
            - etc...
        *}
        {hook name="products:product_tabs"}
            {include file="views/tabs/components/product_tabs.tpl"
                tabs=$tabs
                smarty=$smarty
            }

            {if $blocks.$tabs_block_id.properties.wrapper}
                {include file=$blocks.$tabs_block_id.properties.wrapper
                    content=$smarty.capture.tabsbox_content
                    title=$blocks.$tabs_block_id.description
                }
            {else}
                {$smarty.capture.tabsbox_content nofilter}
            {/if}
        {/hook}
    {/if}
    {* /Product tabs *}

</div>
{* /Product details outer with tabs *}

{capture name="mainbox_title"}{$details_page = true}{/capture}
