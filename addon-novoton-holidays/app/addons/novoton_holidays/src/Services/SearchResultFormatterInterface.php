<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Interface for assigning search-result data to the Smarty view.
 *
 * @since 3.6.0
 */
interface SearchResultFormatterInterface
{
    /**
     * Assign all search-result template variables to the view.
     *
     * @param array $results       Primary result rows
     * @param array $novotonParams Template params (from normalizer)
     * @param array $searchResult  Output from HotelAvailabilitySearcher::search()
     * @param array $altResult     Output from AlternativeDateSearcher::search()
     * @param array $searchParams  Raw (sanitized) request params
     * @param array $debugLog      Debug lines (empty when debug is off)
     */
    public function assignToView(
        array $results,
        array $novotonParams,
        array $searchResult,
        array $altResult,
        array $searchParams,
        array $debugLog
    ): void;

    /**
     * Assign safe defaults so the template renders without secondary errors.
     * Used on early-return (no check-in) and in the error boundary.
     */
    public function assignDefaults(?string $warningLangKey = null): void;
}
