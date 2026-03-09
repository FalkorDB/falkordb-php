# falkordb-php
A FalkorDB client for PHP that uses `phpredis` as its connectivity layer.

## Installation
```bash
composer require falkordb/falkordb-php
```

## Basic usage
```php
<?php
require __DIR__ . '/vendor/autoload.php';

use FalkorDB\FalkorDB;
use FalkorDB\Graph\ConstraintType;
use FalkorDB\Graph\EntityType;

$db = FalkorDB::connect([
    'host' => '127.0.0.1',
    'port' => 6379,
]);

$graph = $db->selectGraph('social');
$graph->query("CREATE (:Person {name:'Alice'})");
$graph->constraintCreate(ConstraintType::UNIQUE, EntityType::NODE, 'Person', 'name');
$result = $graph->query("MATCH (n:Person) RETURN n.name");

var_dump($result->data);
```

## Features
- Single, cluster, and sentinel topology adapters (based on `phpredis`)
- Rich compact-reply parsing (nodes, edges, paths, maps, vectors, point, temporal values)
- Cypher query parameter serialization
- Graph and admin command coverage (`GRAPH.QUERY`, `GRAPH.RO_QUERY`, `GRAPH.INFO`, `GRAPH.LIST`, `GRAPH.CONSTRAINT`, `GRAPH.UDF`, etc.)

## Quality checks
```bash
composer qa
```

## Optional integration tests
```bash
docker compose -f docker/standalone-compose.yml up -d
composer test:integration
```

## Contributing
Contributions are welcome via issues and pull requests.

If you plan to make a significant change, please open an issue first so the approach can be discussed before implementation.

## License
This repository is licensed under the "MIT" license.
