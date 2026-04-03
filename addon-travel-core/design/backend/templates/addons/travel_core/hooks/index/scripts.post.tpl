{script src="js/addons/travel_core/func.js"}

{* Enhance color settings with native color pickers on addon settings page *}
<script>
(function() {ldelim}
    function enhanceColorSettings() {ldelim}
        // Only run on addon settings page for travel_core
        var colorIds = [
            'color_primary', 'color_accent', 'color_text', 'color_text_light',
            'color_bg', 'color_border', 'color_search_btn_bg', 'color_search_btn_hover',
            'color_search_btn_text', 'color_cal_cheapest', 'color_cal_price', 'color_danger'
        ];

        var defaults = {ldelim}
            color_primary: '#003580', color_accent: '#febb02', color_text: '#1a1a1a',
            color_text_light: '#6b6b6b', color_bg: '#ffffff', color_border: '#e0e0e0',
            color_search_btn_bg: '#006ce4', color_search_btn_hover: '#0057b8',
            color_search_btn_text: '#ffffff', color_cal_cheapest: '#2e7d32',
            color_cal_price: '#4B5563', color_danger: '#d32f2f'
        {rdelim};

        colorIds.forEach(function(id) {ldelim}
            // CS-Cart addon settings use name="addon_data[settings][color_primary]" pattern
            var textInput = document.querySelector('input[id*="' + id + '"]');
            if (!textInput || textInput.type === 'hidden' || textInput.dataset.colorEnhanced) return;

            textInput.dataset.colorEnhanced = '1';
            textInput.style.fontFamily = 'monospace';
            textInput.style.width = '110px';
            textInput.setAttribute('maxlength', '7');
            textInput.setAttribute('placeholder', defaults[id] || '#000000');

            var picker = document.createElement('input');
            picker.type = 'color';
            picker.value = (textInput.value && /^#[0-9a-fA-F]{ldelim}6{rdelim}$/.test(textInput.value))
                ? textInput.value
                : (defaults[id] || '#000000');
            picker.style.cssText = 'width:36px;height:30px;border:1px solid #ccc;border-radius:4px;cursor:pointer;padding:1px;vertical-align:middle;margin-right:6px;';

            picker.addEventListener('input', function() {ldelim}
                textInput.value = picker.value;
            {rdelim});

            textInput.addEventListener('input', function() {ldelim}
                var v = textInput.value.trim();
                if (/^#[0-9a-fA-F]{ldelim}6{rdelim}$/.test(v)) {ldelim}
                    picker.value = v;
                {rdelim}
            {rdelim});

            textInput.parentNode.insertBefore(picker, textInput);
        {rdelim});
    {rdelim}

    if (document.readyState === 'loading') {ldelim}
        document.addEventListener('DOMContentLoaded', enhanceColorSettings);
    {rdelim} else {ldelim}
        enhanceColorSettings();
    {rdelim}
{rdelim})();
</script>
