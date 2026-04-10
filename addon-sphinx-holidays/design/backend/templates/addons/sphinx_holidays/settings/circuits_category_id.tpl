{*
 * Sphinx Holidays — "Root category for circuits" picker wrapper.
 * See hotels_category_id.tpl for the input_name convention rationale.
 *}
<div class="control-group setting-wide">
    <label class="control-label" for="elm_sphinx_circuits_category">
        {__("sphinx_holidays.circuits_category_id")}
    </label>
    <div class="controls">
        {include file="pickers/categories/picker.tpl"
            input_name="addon_data[sphinx_holidays][circuits_category_id]"
            data_id="elm_sphinx_circuits_category"
            item_ids=$addon_settings.circuits_category_id|default:0
            hide_link=true
            hide_delete_button=true
            display="radio"
            default_name=__("none")
        }
        <p class="muted description">
            {__("sphinx_holidays.circuits_category_id.tooltip")|default:__("sphinx_holidays.circuits_category_id")}
        </p>
    </div>
</div>
