{*
 * Hook: products:product_detail_bottom
 * Injects booking form after product description on Novoton hotel product pages.
 * Uses ONLY built-in Smarty syntax — no custom plugins needed.
 *}

{* Currently booking form position is 'before_tabs' (default).
   This hook only renders if position is 'after_description'.
   Since we can't read addon settings from template without custom plugins,
   this hook is a no-op for now — the before_tabs hook handles rendering. *}
