<?php

declare(strict_types=1);

namespace FalkorDB\Graph;

enum EntityType: string
{
    case NODE = 'NODE';
    case RELATIONSHIP = 'RELATIONSHIP';
    case EDGE = 'EDGE';
}
