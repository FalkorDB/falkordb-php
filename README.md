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
- Enterprise graph offloading helpers (`GRAPH.OFFLOAD`, `GRAPH.LOAD`, `GRAPH.STUBS`, `GRAPH.STUBINFO`) when the [FalkorDB Enterprise module](https://github.com/FalkorDB/falkordb-enterprise-module) is loaded

## Enterprise graph offloading
These APIs require FalkorDB plus `falkordb_enterprise.so`. Without the enterprise module, the server returns an unknown-command error.

```php
$db = FalkorDB::connect(['host' => '127.0.0.1', 'port' => 6379]);
$graph = $db->selectGraph('social');

$graph->query("CREATE (:Person {name:'Alice'})");
$graph->offload();                 // GRAPH.OFFLOAD social
$db->stubs();                      // GRAPH.STUBS
$graph->stubInfo();                // path/size/mtime/atime/ctime
$graph->load();                    // GRAPH.LOAD social
$graph->load(force: true);         // GRAPH.LOAD social FORCE
```

Equivalent top-level helpers: `$db->offload('social')`, `$db->load('social')`, `$db->stubInfo('social')`.

## Quality checks
```bash
composer qa
```

## Optional integration tests
```bash
docker compose -f docker/standalone-compose.yml up -d
composer test:integration
```
The compose files use `falkordb/falkordb:latest` by default; set
`FALKORDB_VERSION` to test against another tag, e.g.:
```bash
FALKORDB_VERSION=edge docker compose -f docker/standalone-compose.yml up -d
```

## Contributing
Contributions are welcome via issues and pull requests.

If you plan to make a significant change, please open an issue first so the approach can be discussed before implementation.

## License
This repository is licensed under the "MIT" license.
