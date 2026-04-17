{*
 * Sphinx Holidays — "Root category for hotels" picker wrapper.
 *
 * Replaces the legacy numeric text input with CS-Cart's native AJAX
 * category picker (the same widget used on the product details page).
 *
 * ── input_name convention ─────────────────────────────────────────────
 * CS-Cart 4.x renders addon settings inputs as:
 *     name="addon_data[{$addon}][{$setting.name}]"
 *     id="addon_option_{$addon}_{$setting.name}"
 * (see core template views/addons/components/setting_input.tpl)
 *
 * If your CS-Cart build uses a different convention (`addon_data[settings][...]`
 * or flat `addon_data[...]`), inspect the HTML of a working text setting on the
 * same page and adjust the `input_name` parameter below to match.
 *}
<div class="control-group setting-wide">
    <label class="control-label" for="elm_sphinx_hotels_category">
        {__("sphinx_holidays.hotels_category_id")}
    </label>
    <div class="controls">
        {include file="pickers/categories/picker.tpl"
            input_name="addon_data[sphinx_holidays][hotels_category_id]"
            data_id="elm_sphinx_hotels_category"
            item_ids=$addon_settings.hotels_category_id|default:0
            hide_link=true
            hide_delete_button=true
            display="radio"
            default_name=__("none")
        }
        <p class="muted description">
            {__("sphinx_holidays.hotels_category_id.tooltip")|default:__("sphinx_holidays.hotels_category_id")}
        </p>
    </div>
</div>
