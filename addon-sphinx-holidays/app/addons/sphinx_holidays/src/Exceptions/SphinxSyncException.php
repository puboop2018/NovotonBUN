<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Exceptions;

/**
 * Thrown when a Sphinx sync operation fails (data integrity, missing deps, etc.).
 */
class SphinxSyncException extends SphinxException
{
}
