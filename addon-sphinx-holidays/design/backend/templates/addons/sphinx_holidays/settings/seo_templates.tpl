{* Make SEO template inputs full-width and show placeholder reference *}
<style>
    .addon-settings-seo_templates input[type="text"],
    .addon-settings-seo_templates textarea {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
    }
    .addon-settings-seo_templates .control-group .controls {
        max-width: 100%;
    }
    .sph-placeholder-ref {
        background: #f8f9fa;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        padding: 12px 16px;
        margin-bottom: 20px;
    }
    .sph-placeholder-ref summary {
        cursor: pointer;
        font-weight: bold;
        font-size: 13px;
        color: #333;
        padding: 4px 0;
        user-select: none;
    }
    .sph-placeholder-ref summary:hover {
        color: #0d6efd;
    }
    .sph-ph-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2px 24px;
        margin-top: 10px;
    }
    .sph-ph-item {
        font-size: 12px;
        padding: 3px 0;
        line-height: 1.5;
    }
    .sph-ph-item code {
        background: #e9ecef;
        padding: 1px 5px;
        border-radius: 3px;
        font-size: 11px;
        white-space: nowrap;
    }
    .sph-ph-item .sph-ph-desc {
        color: #666;
    }
    .sph-ph-example {
        margin-top: 10px;
        padding-top: 8px;
        border-top: 1px solid #e0e0e0;
        font-size: 12px;
        color: #888;
    }
</style>

<div class="sph-placeholder-ref">
    <details open>
        <summary>{__("sphinx_holidays.available_placeholders")}</summary>
        <div class="sph-ph-grid">
            <div class="sph-ph-item"><code>{"{{name}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_name")}</span></div>
            <div class="sph-ph-item"><code>{"{{classification}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_classification")}</span></div>
            <div class="sph-ph-item"><code>{"{{city}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_city")}</span></div>
            <div class="sph-ph-item"><code>{"{{country}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_country")}</span></div>
            <div class="sph-ph-item"><code>{"{{region}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_region")}</span></div>
            <div class="sph-ph-item"><code>{"{{property_type}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_property_type")}</span></div>
            <div class="sph-ph-item"><code>{"{{facilities}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_facilities")}</span></div>
            <div class="sph-ph-item"><code>{"{{boards}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_boards")}</span></div>
            <div class="sph-ph-item"><code>{"{{rating}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_rating")}</span></div>
            <div class="sph-ph-item"><code>{"{{description}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_description")}</span></div>
            <div class="sph-ph-item"><code>{"{{address}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_address")}</span></div>
            <div class="sph-ph-item"><code>{"{{phone}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_phone")}</span></div>
            <div class="sph-ph-item"><code>{"{{email}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_email")}</span></div>
            <div class="sph-ph-item"><code>{"{{website}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_website")}</span></div>
            <div class="sph-ph-item"><code>{"{{image_url}}"}</code> <span class="sph-ph-desc">- {__("sphinx_holidays.ph_image_url")}</span></div>
            <div class="sph-ph-item"><code>{"{{latitude}}"}</code> / <code>{"{{longitude}}"}</code> <span class="sph-ph-desc">- GPS</span></div>
        </div>
        <div class="sph-ph-example">
            <em>{__("sphinx_holidays.placeholders_example")}</em>
        </div>
    </details>
</div>
