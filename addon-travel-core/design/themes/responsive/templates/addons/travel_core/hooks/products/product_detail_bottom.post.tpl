{*
 * Hotel JSON-LD Structured Data
 * Outputs schema.org Hotel markup for search engine rich results.
 * Data assigned by travel_core's dispatch_before_display hook.
 *}
{if $travel_hotel_schema_json}
<script type="application/ld+json">
{$travel_hotel_schema_json nofilter}
</script>
{/if}
