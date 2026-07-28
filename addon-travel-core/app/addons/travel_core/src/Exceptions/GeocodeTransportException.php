<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Exceptions;

/**
 * Thrown when a reverse-geocoding request fails at the transport level
 * (curl error, non-200 response, unparseable body). Distinct from an empty
 * RESULT (nothing found at those coordinates) so a geocode run can abort
 * and leave the remaining hotels unstamped for the next attempt.
 *
 * Shared by every provider addon: the OSM usage policy is enforced per
 * APPLICATION, so the client, its pacing and its failure mode all live in
 * travel_core rather than being copied per addon.
 */
class GeocodeTransportException extends TravelException
{
}
