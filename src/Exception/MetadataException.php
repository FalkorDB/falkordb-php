<?php

declare(strict_types=1);

namespace FalkorDB\Exception;

/**
 * Indicates that a graph query succeeded but metadata needed to decode its result could not be loaded.
 */
class MetadataException extends FalkorDBException
{
}
