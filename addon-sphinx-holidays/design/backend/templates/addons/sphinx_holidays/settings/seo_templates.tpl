{capture name="sidebar" append}
    <div class="sidebar-row">
        <h6>{__("sphinx_holidays.available_placeholders")}</h6>
        <p class="muted">{__("sphinx_holidays.placeholders_hint")}</p>
        <hr>
        <ul class="unstyled">
            <li><strong>{"{{name}}"}</strong> - {__("sphinx_holidays.ph_name")}</li>
            <li><strong>{"{{classification}}"}</strong> - {__("sphinx_holidays.ph_classification")}</li>
            <li><strong>{"{{city}}"}</strong> - {__("sphinx_holidays.ph_city")}</li>
            <li><strong>{"{{country}}"}</strong> - {__("sphinx_holidays.ph_country")}</li>
            <li><strong>{"{{region}}"}</strong> - {__("sphinx_holidays.ph_region")}</li>
            <li><strong>{"{{property_type}}"}</strong> - {__("sphinx_holidays.ph_property_type")}</li>
            <li><strong>{"{{description}}"}</strong> - {__("sphinx_holidays.ph_description")}</li>
            <li><strong>{"{{rating}}"}</strong> - {__("sphinx_holidays.ph_rating")}</li>
            <li><strong>{"{{facilities}}"}</strong> - {__("sphinx_holidays.ph_facilities")}</li>
            <li><strong>{"{{boards}}"}</strong> - {__("sphinx_holidays.ph_boards")}</li>
            <li><strong>{"{{latitude}}"}</strong> / <strong>{"{{longitude}}"}</strong> - GPS</li>
            <li><strong>{"{{image_url}}"}</strong> - {__("sphinx_holidays.ph_image_url")}</li>
        </ul>
        <hr>
        <p class="muted"><em>{__("sphinx_holidays.placeholders_example")}</em></p>
    </div>
{/capture}
