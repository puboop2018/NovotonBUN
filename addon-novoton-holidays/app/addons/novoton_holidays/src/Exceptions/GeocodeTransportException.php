<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Exceptions;

/**
 * Thrown when a reverse-geocoding request fails at the transport level
 * (curl error, non-200 response, unparseable body). Distinct from a
 * "no street found" result (null) so the geocode cron can abort the run
 * and leave the remaining hotels unstamped for the next attempt.
 */
class GeocodeTransportException extends NovotonException
{
}
