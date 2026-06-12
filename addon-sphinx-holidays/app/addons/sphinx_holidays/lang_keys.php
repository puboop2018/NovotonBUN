<?php
/**
 * Language keys for the Sphinx Holidays addon.
 * Extracted from fn_sphinx_holidays_seed_language_keys() for maintainability.
 *
 * NOTE: the seeder merges these with addon.xml <language_variables>, and
 * addon.xml WINS on conflict. Settings labels/tooltips belong in addon.xml;
 * keep this file for runtime-only keys (SEO templates page, placeholders, ...)
 * that addon.xml does not declare. Editing a key here that also exists in
 * addon.xml has no effect.
 */
return [
    'sphinx_holidays.circuits_category_id' => [
        'en' => 'CS-Cart category ID for circuits',
        'ro' => 'ID categorie CS-Cart pentru circuite',
    ],
    'sphinx_holidays.circuits_category_id.tooltip' => [
        'en' => 'Root category under which circuit products are created. Country sub-categories are generated automatically.',
        'ro' => 'Categorie rădăcină pentru produse circuite. Sub-categoriile pe țări se generează automat.',
    ],
    'sphinx_holidays.experiences_category_id' => [
        'en' => 'CS-Cart category ID for experiences',
        'ro' => 'ID categorie CS-Cart pentru experiențe',
    ],
    'sphinx_holidays.experiences_category_id.tooltip' => [
        'en' => 'Root category under which experience products are created. Country sub-categories are generated automatically.',
        'ro' => 'Categorie rădăcină pentru produse experiențe. Sub-categoriile pe țări se generează automat.',
    ],
    'sphinx_holidays.product_languages' => [
        'en' => 'Product languages',
        'ro' => 'Limbi produse',
    ],
    'sphinx_holidays.product_languages.tooltip' => [
        'en' => 'Select which CS-Cart languages to create hotel product descriptions for. Hotels will only appear in the storefront for selected languages.',
        'ro' => 'Selectati pentru care limbi CS-Cart sa se creeze descrierile produselor hoteliere. Hotelurile vor aparea in magazin doar pentru limbile selectate.',
    ],
    'sphinx_holidays.product_code_prefix' => [
        'en' => 'Product code prefix',
        'ro' => 'Prefix cod produs',
    ],
    'sphinx_holidays.product_code_prefix.tooltip' => [
        'en' => 'Prefix for CS-Cart product codes created from Sphinx hotels (e.g. SPX). The hotel ID is appended to form the full code (e.g. SPX12345).',
        'ro' => 'Prefix pentru codurile de produs CS-Cart create din hoteluri Sphinx (ex. SPX). ID-ul hotelului se adaugă pentru codul complet (ex. SPX12345).',
    ],
    'sphinx_holidays.default_product_quantity' => [
        'en' => 'Default product quantity',
        'ro' => 'Cantitate implicită produs',
    ],
    'sphinx_holidays.default_product_quantity.tooltip' => [
        'en' => 'Inventory quantity assigned to hotel products on creation. Set high to prevent "zero inventory" errors.',
        'ro' => 'Cantitatea de inventar atribuită produselor hotel la creare. Setați o valoare mare pentru a preveni erorile de "inventar zero".',
    ],
    'sphinx_holidays.skip_no_description' => [
        'en' => 'Skip hotels without description',
        'ro' => 'Omite hoteluri fără descriere',
    ],
    'sphinx_holidays.skip_no_description.tooltip' => [
        'en' => 'Do not create CS-Cart products for hotels that have an empty description from the API.',
        'ro' => 'Nu crea produse CS-Cart pentru hoteluri care nu au descriere din API.',
    ],
    'sphinx_holidays.require_immediate_availability' => [
        'en' => 'Require immediate availability (sync gate + storefront offers)',
        'ro' => 'Necesită disponibilitate imediată (filtru sync + oferte storefront)',
    ],
    'sphinx_holidays.require_immediate_availability.tooltip' => [
        'en' => 'When enabled, the hotels cron only adds hotels that have at least one offer with confirmation=immediate, and the storefront search shows only immediate-confirmation offers. The hotels cron accepts a per-run override: &availability_gate=0 to skip the gate, &availability_gate=1 to force it.',
        'ro' => 'Când este activ, cronul de hoteluri adaugă doar hotelurile care au cel puțin o ofertă cu confirmation=immediate, iar căutarea din storefront afișează doar ofertele cu confirmare imediată. Cronul de hoteluri acceptă o suprascriere per rulare: &availability_gate=0 pentru a omite filtrul, &availability_gate=1 pentru a-l forța.',
    ],
    // General Settings section headers & fields
    'sphinx_holidays.api_header' => ['en' => 'Sphinx API Settings', 'ro' => 'Setări API Sphinx'],
    'sphinx_holidays.api_base_url' => ['en' => 'API Base URL', 'ro' => 'URL bază API'],
    'sphinx_holidays.api_key' => ['en' => 'API Key (Bearer Token)', 'ro' => 'Cheie API (Bearer Token)'],
    'sphinx_holidays.enable_api_cache' => ['en' => 'Enable API response caching', 'ro' => 'Activează cache-ul răspunsurilor API'],
    'sphinx_holidays.cache_ttl_search' => ['en' => 'Search cache TTL (seconds)', 'ro' => 'TTL cache căutare (secunde)'],
    'sphinx_holidays.search_header' => ['en' => 'Search Settings', 'ro' => 'Setări Căutare'],
    'sphinx_holidays.default_currency' => ['en' => 'Default search currency', 'ro' => 'Monedă implicită căutare'],
    'sphinx_holidays.ignore_domains' => ['en' => 'Ignore domains (comma-separated supplier IDs to skip)', 'ro' => 'Domenii ignorate (ID-uri furnizori separați prin virgulă)'],
    'sphinx_holidays.search_poll_interval' => ['en' => 'Search poll interval (seconds)', 'ro' => 'Interval verificare căutare (secunde)'],
    'sphinx_holidays.search_max_polls' => ['en' => 'Maximum search polls before timeout', 'ro' => 'Număr maxim de verificări înainte de timeout'],
    'sphinx_holidays.pricing_header' => ['en' => 'Pricing', 'ro' => 'Prețuri'],
    'sphinx_holidays.commission' => ['en' => 'Commission percentage', 'ro' => 'Procentaj comision'],
    'sphinx_holidays.product_header' => ['en' => 'Product Creation & Mapping', 'ro' => 'Creare & Mapare Produse'],
    'sphinx_holidays.hotels_category_id' => ['en' => 'CS-Cart category ID for Sphinx hotels', 'ro' => 'ID categorie CS-Cart pentru hoteluri Sphinx'],
    'sphinx_holidays.hotels_category_id.tooltip' => ['en' => 'Root category under which hotel products are created. Country sub-categories are generated automatically.', 'ro' => 'Categorie rădăcină în care se creează produsele hoteliere. Sub-categoriile pe țări se generează automat.'],
    'sphinx_holidays.packages_category_id' => ['en' => 'CS-Cart category ID for packages', 'ro' => 'ID categorie CS-Cart pentru pachete'],
    'sphinx_holidays.packages_category_id.tooltip' => ['en' => 'Root category under which package products are created. Country sub-categories are generated automatically.', 'ro' => 'Categorie rădăcină pentru produse pachete. Sub-categoriile pe țări se generează automat.'],
    'sphinx_holidays.resilience_header' => ['en' => 'API Resilience', 'ro' => 'Reziliență API'],
    'sphinx_holidays.api_max_retries' => ['en' => 'Maximum retries on failure', 'ro' => 'Număr maxim de reîncercări'],
    'sphinx_holidays.api_retry_delay_ms' => ['en' => 'Initial retry delay (ms)', 'ro' => 'Întârziere inițială reîncercare (ms)'],
    'sphinx_holidays.api_retry_multiplier' => ['en' => 'Retry delay multiplier', 'ro' => 'Multiplicator întârziere reîncercare'],
    'sphinx_holidays.circuit_breaker_threshold' => ['en' => 'Circuit breaker threshold (failures before open)', 'ro' => 'Prag circuit breaker (eșecuri înainte de deschidere)'],
    'sphinx_holidays.circuit_breaker_timeout' => ['en' => 'Circuit breaker timeout (seconds)', 'ro' => 'Timeout circuit breaker (secunde)'],
    'sphinx_holidays.cron_header' => ['en' => 'Cron Settings', 'ro' => 'Setări Cron'],
    'sphinx_holidays.cron_access_key' => ['en' => 'Cron access key', 'ro' => 'Cheie acces cron'],
    'sphinx_holidays.cron_access_key.tooltip' => ['en' => 'Access key for cron URL: index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=hotels', 'ro' => 'Cheie acces pentru URL cron: index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=hotels'],
    'sphinx_holidays.cron_commands' => ['en' => 'Cron Commands', 'ro' => 'Comenzi Cron'],
    'sphinx_holidays.debug_header' => ['en' => 'Debug', 'ro' => 'Depanare'],
    'sphinx_holidays.debug_logging' => ['en' => 'Enable debug logging', 'ro' => 'Activează logare depanare'],
    'sphinx_holidays.addon_settings' => [
        'en' => 'Settings',
        'ro' => 'Setări',
    ],
    'sphinx_holidays.show_whitelisted_only' => [
        'en' => 'Show whitelisted only',
        'ro' => 'Arată doar cele din whitelist',
    ],
    'sphinx_holidays.classification' => [
        'en' => 'Classification',
        'ro' => 'Clasificare',
    ],
    'sphinx_holidays.link_status' => [
        'en' => 'Link Status',
        'ro' => 'Status Legătură',
    ],
    'sphinx_holidays.linked' => [
        'en' => 'Linked',
        'ro' => 'Legat',
    ],
    'sphinx_holidays.orphan' => [
        'en' => 'Orphan',
        'ro' => 'Orfan',
    ],
    'sphinx_holidays.product' => [
        'en' => 'Product',
        'ro' => 'Produs',
    ],
    'sphinx_holidays.unclassified' => [
        'en' => 'Unclassified',
        'ro' => 'Neclasificat',
    ],
    'sphinx_holidays.bulk_activate' => [
        'en' => 'Activate Selected',
        'ro' => 'Activează Selectate',
    ],
    'sphinx_holidays.bulk_deactivate' => [
        'en' => 'Deactivate Selected',
        'ro' => 'Dezactivează Selectate',
    ],
    'sphinx_holidays.bulk_delete' => [
        'en' => 'Delete Selected',
        'ro' => 'Șterge Selectate',
    ],
    'sphinx_holidays.bulk_sync_images' => [
        'en' => 'Sync Images',
        'ro' => 'Sincronizează Imagini',
    ],
    'sphinx_holidays.bulk_delete_confirm' => [
        'en' => 'Are you sure you want to delete the selected hotels?',
        'ro' => 'Sigur doriți să ștergeți hotelurile selectate?',
    ],
    'sphinx_holidays.images_synced' => [
        'en' => 'Images synced',
        'ro' => 'Imagini sincronizate',
    ],
    'sphinx_holidays.hotels_updated' => [
        'en' => 'Hotels updated',
        'ro' => 'Hoteluri actualizate',
    ],
    'sphinx_holidays.hotels_deleted' => [
        'en' => 'Hotels deleted',
        'ro' => 'Hoteluri șterse',
    ],
    'sphinx_holidays.no_hotels_selected' => [
        'en' => 'No hotels selected',
        'ro' => 'Niciun hotel selectat',
    ],
    'sphinx_holidays.all_classifications' => [
        'en' => 'All classifications',
        'ro' => 'Toate clasificările',
    ],
    'sphinx_holidays.all_property_types' => [
        'en' => 'All types',
        'ro' => 'Toate tipurile',
    ],
    'sphinx_holidays.all_link_statuses' => [
        'en' => 'All',
        'ro' => 'Toate',
    ],
    'sphinx_holidays.with_selected' => [
        'en' => 'With selected',
        'ro' => 'Cu cele selectate',
    ],
    'sphinx_holidays.skipped_hotels' => [
        'en' => 'Skipped hotels',
        'ro' => 'Hoteluri omise',
    ],
    'sphinx_holidays.retry_skipped' => [
        'en' => 'Retry skipped hotels',
        'ro' => 'Reîncearcă hotelurile omise',
    ],
    'sphinx_holidays.retry_skipped_confirm' => [
        'en' => 'This will reset all skipped hotels so they can be processed again. Continue?',
        'ro' => 'Aceasta va reseta toate hotelurile omise pentru a fi procesate din nou. Continuați?',
    ],
    'sphinx_holidays.skipped_reset' => [
        'en' => '[count] skipped hotel(s) have been reset and are now eligible for product creation.',
        'ro' => '[count] hotel(uri) omise au fost resetate și sunt acum eligibile pentru crearea de produse.',
    ],
    'sphinx_holidays.no_skipped_hotels' => [
        'en' => 'No skipped hotels found.',
        'ro' => 'Nu s-au găsit hoteluri omise.',
    ],
    // SEO Templates section
    'sphinx_holidays.seo_templates' => [
        'en' => 'SEO Templates',
        'ro' => 'Șabloane SEO',
    ],
    'sphinx_holidays.seo_placeholders_info' => [
        'en' => 'Use the placeholders listed in the sidebar to build your SEO templates.',
        'ro' => 'Folosiți placeholder-ele din bara laterală pentru a construi șabloanele SEO.',
    ],
    // Sidebar placeholder reference (used in settings/seo_templates.tpl)
    'sphinx_holidays.available_placeholders' => [
        'en' => 'Available Placeholders',
        'ro' => 'Placeholder-e Disponibile',
    ],
    'sphinx_holidays.seo_placeholders_title' => [
        'en' => 'Sphinx placeholders to use',
        'ro' => 'Placeholder-e Sphinx disponibile',
    ],
    'sphinx_holidays.placeholders_hint' => [
        'en' => 'Use these tags in your SEO templates:',
        'ro' => 'Folosiți aceste tag-uri în șabloanele SEO:',
    ],
    'sphinx_holidays.placeholders_example' => [
        'en' => 'Example: Book {{name}} in {{city}}, {{country}}. {{classification}}-star {{property_type}} with {{facilities}}.',
        'ro' => 'Exemplu: Rezervă {{name}} în {{city}}, {{country}}. {{property_type}} {{classification}} stele cu {{facilities}}.',
    ],
    'sphinx_holidays.ph_name' => ['en' => 'Hotel name', 'ro' => 'Nume hotel'],
    'sphinx_holidays.ph_classification' => ['en' => 'Star rating', 'ro' => 'Clasificare stele'],
    'sphinx_holidays.ph_city' => ['en' => 'City / resort', 'ro' => 'Oraș / stațiune'],
    'sphinx_holidays.ph_country' => ['en' => 'Country', 'ro' => 'Țară'],
    'sphinx_holidays.ph_region' => ['en' => 'Region', 'ro' => 'Regiune'],
    'sphinx_holidays.ph_property_type' => ['en' => 'Hotel / villa / apt', 'ro' => 'Hotel / vilă / apt'],
    'sphinx_holidays.ph_description' => ['en' => 'API description', 'ro' => 'Descriere API'],
    'sphinx_holidays.ph_rating' => ['en' => 'Guest rating', 'ro' => 'Rating oaspeți'],
    'sphinx_holidays.ph_facilities' => ['en' => 'Top 3 facilities', 'ro' => 'Top 3 facilități'],
    'sphinx_holidays.ph_boards' => ['en' => 'Meal plans', 'ro' => 'Tipuri masă'],
    'sphinx_holidays.ph_image_url' => ['en' => 'Main image URL', 'ro' => 'URL imagine principală'],
    'sphinx_holidays.ph_address' => ['en' => 'Street address', 'ro' => 'Adresă stradă'],
    'sphinx_holidays.ph_phone' => ['en' => 'Phone number', 'ro' => 'Număr telefon'],
    'sphinx_holidays.ph_email' => ['en' => 'Email address', 'ro' => 'Adresă email'],
    'sphinx_holidays.ph_website' => ['en' => 'Website URL', 'ro' => 'URL website'],
    'sphinx_holidays.seo_product_name' => [
        'en' => 'Product name',
        'ro' => 'Nume produs',
    ],
    'sphinx_holidays.seo_product_name.tooltip' => [
        'en' => 'Template for the product name.',
        'ro' => 'Șablon pentru numele produsului.',
    ],
    'sphinx_holidays.seo_page_title' => [
        'en' => 'Page title',
        'ro' => 'Titlu pagină',
    ],
    'sphinx_holidays.seo_page_title.tooltip' => [
        'en' => 'Template for the HTML page title (SEO). Google typically truncates around 60 characters.',
        'ro' => 'Șablon pentru titlul paginii HTML (SEO). Google trunchiază de obicei la ~60 caractere.',
    ],
    'sphinx_holidays.seo_meta_description' => [
        'en' => 'Meta description',
        'ro' => 'Meta descriere',
    ],
    'sphinx_holidays.seo_meta_description.tooltip' => [
        'en' => 'Template for the meta description tag. Google truncates around 160 characters.',
        'ro' => 'Șablon pentru tag-ul meta description. Google trunchiază la ~160 caractere.',
    ],
    'sphinx_holidays.seo_meta_keywords' => [
        'en' => 'Meta keywords',
        'ro' => 'Meta cuvinte cheie',
    ],
    'sphinx_holidays.seo_meta_keywords.tooltip' => [
        'en' => 'Template for the meta keywords tag.',
        'ro' => 'Șablon pentru tag-ul meta keywords.',
    ],
    'sphinx_holidays.seo_name_slug' => [
        'en' => 'SEO URL slug',
        'ro' => 'URL SEO (slug)',
    ],
    'sphinx_holidays.seo_name_slug.tooltip' => [
        'en' => 'Template for the SEO-friendly URL slug. Result is automatically sanitized.',
        'ro' => 'Șablon pentru slug-ul URL SEO. Rezultatul este sanitizat automat.',
    ],
    'sphinx_holidays.seo_full_description' => [
        'en' => 'Full description (optional)',
        'ro' => 'Descriere completă (opțional)',
    ],
    'sphinx_holidays.seo_full_description.tooltip' => [
        'en' => 'Optional template to wrap or replace the API description. Leave empty to use the raw API description as-is.',
        'ro' => 'Șablon opțional pentru a înfășura sau înlocui descrierea API. Lăsați gol pentru a folosi descrierea API în forma originală.',
    ],
    'sphinx_holidays.per_night' => [
        'en' => 'night',
        'ro' => 'noapte',
    ],
];
