<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Master travel provider contract.
 *
 * Each travel API addon registers a provider that implements this interface.
 * It aggregates all sub-interfaces (search, booking, price verification).
 */
interface TravelProviderInterface
{
    /**
     * Get the unique provider identifier (e.g., 'novoton', 'sphinx').
     */
    public function getName(): string;

    /**
     * Get the human-readable provider label (e.g., 'Novoton Holidays', 'Sphinx/Christian Tour').
     */
    public function getLabel(): string;

    /**
     * Whether this provider is currently enabled and configured.
     */
    public function isActive(): bool;

    /**
     * Get the search adapter for this provider.
     */
    public function getSearchAdapter(): SearchAdapterInterface;

    /**
     * Get the booking submitter for this provider.
     */
    public function getBookingSubmitter(): BookingSubmitterInterface;

    /**
     * Get the price verifier for this provider.
     */
    public function getPriceVerifier(): PriceVerifierInterface;

    /**
     * Get the data normalizer for this provider.
     */
    public function getNormalizer(): ProviderNormalizerInterface;
}
