<?php

declare(strict_types=1);

namespace FalkorDB\Parser;

final class GraphValueType
{
    public const UNKNOWN = 0;
    public const NULL = 1;
    public const STRING = 2;
    public const INTEGER = 3;
    public const BOOLEAN = 4;
    public const DOUBLE = 5;
    public const ARRAY = 6;
    public const EDGE = 7;
    public const NODE = 8;
    public const PATH = 9;
    public const MAP = 10;
    public const POINT = 11;
    public const VECTORF32 = 12;
    public const DATETIME = 13;
    public const DATE = 14;
    public const TIME = 15;
    public const DURATION = 16;
}
