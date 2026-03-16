<?php
declare(strict_types=1);
/**
 * Shared output callback pattern for sync classes.
 *
 * Replaces the identical private output() + output_callback property
 * that was copy-pasted across BatchedHotelInfoSync, BatchedPriceInfoSync,
 * and BatchedHotelFacilitiesSync.
 *
 * Usage:
 *   class MySync {
 *       use OutputWriterTrait;
 *       ...
 *       $this->output("Processing hotel 123...");
 *   }
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

trait OutputWriterTrait
{
    /** @var callable|null */
    private $output_callback = null;

    /**
     * Set output callback for custom output handling.
     * When set, all output() calls are routed through this callback
     * instead of writing to stdout.
     */
    public function setOutputCallback(callable $callback): void
    {
        $this->output_callback = $callback;
    }

    /**
     * Write a message to the configured output target.
     *
     * If an output_callback is set (e.g. by the admin controller for
     * streaming HTML, or by tests for capture), the callback receives
     * the formatted string.  Otherwise falls back to echo + flush.
     */
    protected function output(string $message, bool $newline = true): void
    {
        $formatted = $message . ($newline ? "\n" : "");

        if ($this->output_callback) {
            call_user_func($this->output_callback, $formatted);
        } else {
            echo $formatted;
            flush();
        }
    }
}
