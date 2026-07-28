{**
 * Travel Core — per-language SEO template fields (shared component).
 *
 * Included by both providers' SEO Templates pages (novoton_seo_templates /
 * sphinx_seo_templates) INSIDE their <form>. Renders:
 *   1. the GLOBAL field toggles (they gate rendering for every language);
 *   2. one section per storefront language with the six template fields,
 *      posted as seo_lang[<lang>][<key>] and saved to "<key>__<lang>"
 *      settings by fn_travel_core_seo_save_lang_templates().
 *
 * Expects in scope (assigned by the provider controller):
 *   $seo_values       – global values incl. seo_field_* toggles
 *   $seo_languages    – lang_code => language name
 *   $seo_lang_values  – lang_code => [template_key => effective value]
 *}

{$seo_tpl_fields = [
    ['key' => 'seo_product_name',     'type' => 'input',    'ideal' => '80',  'max' => '255', 'rows' => '',  'label' => 'travel_core.seo_product_name',     'fallback' => 'Product name template',                'desc' => 'travel_core.seo_product_name_desc'],
    ['key' => 'seo_page_title',       'type' => 'input',    'ideal' => '60',  'max' => '255', 'rows' => '',  'label' => 'travel_core.seo_page_title',       'fallback' => 'Page title template',                  'desc' => 'travel_core.seo_page_title_desc'],
    ['key' => 'seo_meta_description', 'type' => 'textarea', 'ideal' => '160', 'max' => '500', 'rows' => '3', 'label' => 'travel_core.seo_meta_description', 'fallback' => 'Meta description template',            'desc' => 'travel_core.seo_meta_description_desc'],
    ['key' => 'seo_meta_keywords',    'type' => 'input',    'ideal' => '200', 'max' => '255', 'rows' => '',  'label' => 'travel_core.seo_meta_keywords',    'fallback' => 'Meta keywords template',               'desc' => 'travel_core.seo_meta_keywords_desc'],
    ['key' => 'seo_name_slug',        'type' => 'input',    'ideal' => '80',  'max' => '255', 'rows' => '',  'label' => 'travel_core.seo_name_slug',        'fallback' => 'SEO URL slug template',                'desc' => 'travel_core.seo_name_slug_desc'],
    ['key' => 'seo_full_description', 'type' => 'textarea', 'ideal' => '',    'max' => '',    'rows' => '4', 'label' => 'travel_core.seo_full_description', 'fallback' => 'Full description template (optional)', 'desc' => 'travel_core.seo_full_description_desc']
]}

{* ── Global field toggles ── *}
<div class="well well-small seo-field-toggles">
    <strong>{__("travel_core.seo_fields_applied")|default:"Fields applied on import"}</strong>
    <p class="muted seo-field-toggles-hint">
        {__("travel_core.seo_fields_applied_hint")|default:"Unchecked fields are skipped for every language."}
    </p>
    {foreach $seo_tpl_fields as $f}
        {$toggle_key = "seo_field_"|cat:($f.key|replace:'seo_':'')}
        <label class="checkbox inline seo-field-toggle">
            <input type="checkbox"
                   name="seo[{$toggle_key}]"
                   value="Y"
                   {if $seo_values.$toggle_key != 'N'}checked="checked"{/if} />
            {__($f.label)}
        </label>
    {/foreach}
</div>

<p class="muted seo-lang-fallback-hint">
    {__("travel_core.seo_per_language_hint")|default:"Each storefront language has its own template set. A language whose template was never saved uses the built-in default."}
</p>

{* ── One section per storefront language ── *}
{foreach $seo_languages as $seo_lc => $seo_lang_name}
    <div class="seo-lang-section">
        <h4 class="seo-lang-heading">
            {$seo_lang_name} <span class="seo-lang-code">({$seo_lc})</span>
        </h4>

        {foreach $seo_tpl_fields as $f}
            <div class="control-group">
                <label class="control-label" for="{$seo_lc}_{$f.key}">{__($f.label)}</label>
                <div class="controls">
                    {if $f.type == 'textarea'}
                        <textarea id="{$seo_lc}_{$f.key}"
                                  name="seo_lang[{$seo_lc}][{$f.key}]"
                                  {if $f.ideal}data-seo-ideal="{$f.ideal}"{/if}
                                  {if $f.max}data-seo-max="{$f.max}"{/if}
                                  rows="{$f.rows}">{$seo_lang_values[$seo_lc][$f.key]|escape:html}</textarea>
                    {else}
                        <input type="text"
                               id="{$seo_lc}_{$f.key}"
                               name="seo_lang[{$seo_lc}][{$f.key}]"
                               {if $f.ideal}data-seo-ideal="{$f.ideal}"{/if}
                               {if $f.max}data-seo-max="{$f.max}"{/if}
                               value="{$seo_lang_values[$seo_lc][$f.key]|escape:html}" />
                    {/if}
                    <p class="help-block">{__($f.desc)}</p>
                </div>
            </div>
        {/foreach}
    </div>
{/foreach}
