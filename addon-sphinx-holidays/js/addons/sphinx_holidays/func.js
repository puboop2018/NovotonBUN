(function(_, $) {
    $(document).ready(function() {
        var $addonSettings = $('#addon_options_sphinx_holidays');

        if ($addonSettings.length) {
            $addonSettings.find('input[type="text"]').addClass('input-text-large');
            $addonSettings.find('input[type="password"]').addClass('input-text-large');
            $addonSettings.find('textarea').addClass('input-textarea-long');
        }

        var $contentGeneral = $('#content_general');

        if ($contentGeneral.length) {
            $contentGeneral.find('input[type="text"]').addClass('input-text-large');
            $contentGeneral.find('input[type="password"]').addClass('input-text-large');
            $contentGeneral.find('textarea').addClass('input-textarea-long');
        }
    });
}(Tygh, Tygh.$));
