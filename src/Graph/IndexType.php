<?php

declare(strict_types=1);

namespace FalkorDB\Graph;

enum IndexType: string
{
    case RANGE = 'RANGE';
    case FULLTEXT = 'FULLTEXT';
    case VECTOR = 'VECTOR';
}
