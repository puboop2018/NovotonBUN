<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Enums;

/**
 * SEO template overwrite strategy when applying templates to existing products.
 *
 * Backed enum — values map directly to the addon.xml selectbox variants
 * stored in ?:settings via CS-Cart's Settings API.
 */
enum SeoOverwriteMode: string
{
    /** Always overwrite, even if the product field already has a value. */
    case OverrideAll = 'override_all';

    /** Only fill fields that are currently empty. */
    case FillIfEmpty = 'fill_if_empty';
}
