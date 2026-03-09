# Docker environments for integration testing

## Standalone
```bash
docker compose -f docker/standalone-compose.yml up -d
FALKORDB_RUN_INTEGRATION=1 FALKORDB_HOST=127.0.0.1 FALKORDB_PORT=6379 composer test:integration
```

## Cluster
```bash
docker compose -f docker/cluster-compose.yml up -d
FALKORDB_RUN_INTEGRATION=1 \
FALKORDB_RUN_CLUSTER_INTEGRATION=1 \
FALKORDB_CLUSTER_SEEDS=127.0.0.1:17000,127.0.0.1:17001,127.0.0.1:17002 \
composer test:integration
```

## Sentinel
```bash
docker compose -f docker/sentinel-compose.yml up -d
FALKORDB_RUN_INTEGRATION=1 \
FALKORDB_RUN_SENTINEL_INTEGRATION=1 \
FALKORDB_SENTINEL_HOST=127.0.0.1 \
FALKORDB_SENTINEL_PORT=26379 \
FALKORDB_SENTINEL_MASTER_NAME=mymaster \
FALKORDB_SENTINEL_REDIS_HOST=127.0.0.1 \
FALKORDB_SENTINEL_REDIS_PORT=6380 \
composer test:integration
```

## Cleanup
```bash
docker compose -f docker/standalone-compose.yml down -v
docker compose -f docker/cluster-compose.yml down -v
docker compose -f docker/sentinel-compose.yml down -v
```
