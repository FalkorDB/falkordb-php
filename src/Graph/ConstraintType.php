<?php

declare(strict_types=1);

namespace FalkorDB\Graph;

enum ConstraintType: string
{
    case MANDATORY = 'MANDATORY';
    case UNIQUE = 'UNIQUE';
}
