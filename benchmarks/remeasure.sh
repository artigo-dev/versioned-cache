#!/usr/bin/env bash
#
# This file is part of the artigo/versioned-cache package.
#
# For the full copyright and license information, please view the LICENSE
# file that was distributed with this source code.
#
# Every timing the documentation quotes, run again on a quiet machine: each
# benchmark RUNS times (5), then min / median / max of every number it prints,
# labelled with the section of the docs it feeds. Counts that do not move
# between runs print once.
#
#   bash benchmarks/remeasure.sh                      # everything: several hours
#   ONLY=soft,marshaller bash benchmarks/remeasure.sh # a subset (keys below)
#   RUNS=3 OUT=/tmp/vc bash benchmarks/remeasure.sh
#
# Keys for ONLY: headline unique behind adopt guard ban ban-cluster onecall
#                request marshaller soft
#
# Isolation: it creates a Docker network and containers of its own, named
# vcbench-<pid>-*, publishes no port and uses no host network; the benchmarks
# reach Redis by container name. On exit, Ctrl-C included, it removes what it
# created and nothing else. Run it where nothing else is timing-sensitive.
#
# Settings (environment):
#   RUNS             runs per benchmark (5)
#   ONLY             comma-separated keys, empty for all
#   OUT              where the raw output and the summary go
#                    (${TMPDIR:-/tmp}/vc-remeasure-<date>)
#   PHP_IMAGE        an image with PHP 8.5, phpredis 6.3, APCu, ext-zstd and
#                    composer; built from php:8.5-cli when it does not exist
#   REDIS_IMAGE      redis:8-alpine
#   TOXIPROXY_IMAGE  ghcr.io/shopify/toxiproxy:2.12.0
#   SYMFONY_CACHE    the symfony/cache constraint the copy is updated to (8.1.*)
#   KEEP=1           leave the containers running (for a look inside)

set -euo pipefail

RUNS=${RUNS:-5}
ONLY=${ONLY:-}
OUT=${OUT:-"${TMPDIR:-/tmp}/vc-remeasure-$(date +%Y%m%d-%H%M%S)"}
PHP_IMAGE=${PHP_IMAGE:-vcbench-php:8.5}
REDIS_IMAGE=${REDIS_IMAGE:-redis:8-alpine}
TOXIPROXY_IMAGE=${TOXIPROXY_IMAGE:-ghcr.io/shopify/toxiproxy:2.12.0}
SYMFONY_CACHE=${SYMFONY_CACHE:-8.1.*}
KEEP=${KEEP:-}

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
PREFIX="vcbench-$$"
NETWORK="$PREFIX-net"
PHP="$PREFIX-php"
REDIS="$PREFIX-redis"
CLUSTER="$PREFIX-cluster"
BEHIND="$PREFIX-behind"
PROXY="$PREFIX-proxy"
CREATED=()

# Git Bash on Windows would turn /work into a Windows path
export MSYS_NO_PATHCONV=1

teardown() {
    if [ -n "$KEEP" ]; then
        echo "KEEP=1: leaving ${CREATED[*]:-nothing} and $NETWORK" >&2

        return
    fi

    # only what this run created, by the names it gave them (an empty array
    # is unbound to bash 4.3 under set -u, hence the + form)
    for name in ${CREATED[@]+"${CREATED[@]}"}; do
        docker rm -f "$name" >/dev/null 2>&1 || true
    done

    docker network rm "$NETWORK" >/dev/null 2>&1 || true
}

trap teardown EXIT
trap 'exit 130' INT TERM

wanted() {
    [ -z "$ONLY" ] || [[ ",$ONLY," == *",$1,"* ]]
}

start() {
    local name=$1
    shift
    # no -p, no --network host: reachable on $NETWORK only
    docker run -d --name "$name" --network "$NETWORK" "$@" >/dev/null
    CREATED+=("$name")
}

php_exec() {
    docker exec -w /work/vc "$PHP" "$@"
}

setup() {
    mkdir -p "$OUT"

    if ! docker image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
        echo "building $PHP_IMAGE from php:8.5-cli"
        docker build -t "$PHP_IMAGE" - <<'DOCKERFILE'
FROM php:8.5-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && yes '' | pecl install redis apcu zstd \
    && docker-php-ext-enable redis apcu zstd \
    && docker-php-ext-install pcntl \
    && echo 'memory_limit=-1' > /usr/local/etc/php/conf.d/memory.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
DOCKERFILE
    fi

    docker network create "$NETWORK" >/dev/null

    start "$REDIS" "$REDIS_IMAGE" redis-server --maxmemory-policy volatile-lru --save ""
    start "$BEHIND" "$REDIS_IMAGE" redis-server --maxmemory-policy volatile-ttl --save ""
    start "$PROXY" "$TOXIPROXY_IMAGE" -host=0.0.0.0
    # three masters with a replica each in one container, as compose.tests.yaml's
    # cluster-replicas profile, announcing the address the network gave it
    # Redis 8.12 starts a cluster node without TLS only when told that its bus
    # port is reachable by trusted hosts alone; an older one does not know the option
    start "$CLUSTER" "$REDIS_IMAGE" sh -c 'ip=$(hostname -i | cut -d" " -f1);
        bus="--cluster-bus-port-protected-mode no";
        redis-server --port 7199 $bus --save "" --daemonize yes >/dev/null 2>&1; sleep 0.5;
        if redis-cli -p 7199 ping >/dev/null 2>&1; then redis-cli -p 7199 shutdown nosave >/dev/null 2>&1; else bus=""; fi;
        for port in 7110 7111 7112 7113 7114 7115; do
            redis-server --port $port --cluster-enabled yes $bus --cluster-config-file nodes-$port.conf \
                --cluster-announce-ip $ip --maxmemory-policy volatile-ttl --save "" --daemonize yes;
        done;
        until redis-cli -p 7115 ping >/dev/null 2>&1; do sleep 0.2; done;
        redis-cli --cluster create $ip:7110 $ip:7111 $ip:7112 $ip:7113 $ip:7114 $ip:7115 --cluster-replicas 1 --cluster-yes >/dev/null;
        tail -f /dev/null'
    start "$PHP" "$PHP_IMAGE" sleep infinity

    # the code, copied into the container: a bind mount makes class loading
    # slow enough to show in the timings
    docker exec "$PHP" mkdir -p /work/vc
    tar -C "$ROOT" --exclude=./.git --exclude=./.idea --exclude=./vendor --exclude=./.phpunit.cache \
        --exclude=./docs --exclude='./remeasure-*' -cf - . | docker exec -i "$PHP" tar -C /work/vc -xf -
    php_exec composer install --prefer-dist --no-interaction --no-progress --quiet
    php_exec composer update symfony/cache artigo/cache-stampede --with "symfony/cache:$SYMFONY_CACHE" \
        --with-dependencies --prefer-dist --no-interaction --no-progress --quiet

    for name in "$REDIS" "$BEHIND"; do
        until docker exec "$name" redis-cli ping >/dev/null 2>&1; do sleep 0.2; done
    done

    # healthy once the slot map names every replica and each has synced
    local tries=0
    until docker exec "$CLUSTER" sh -c '[ $(redis-cli -p 7110 cluster slots 2>/dev/null | grep -cE "^711[345]$") -ge 3 ] \
        && for p in 7110 7111 7112 7113 7114 7115; do redis-cli -p $p cluster info | grep -q cluster_state:ok || exit 1; done \
        && for p in 7113 7114 7115; do redis-cli -p $p info replication | grep -q master_link_status:up || exit 1; done' 2>/dev/null; do
        tries=$((tries + 1))
        [ "$tries" -lt 120 ] || { echo "the cluster did not come up" >&2; exit 1; }
        sleep 0.5
    done

    CLUSTER_IP=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NETWORK\").IPAddress}}" "$CLUSTER")
    CLUSTER_DSN="redis:?host[$CLUSTER_IP:7110]&host[$CLUSTER_IP:7111]&host[$CLUSTER_IP:7112]&redis_cluster=1"

    {
        echo "date        $(date -u +%Y-%m-%dT%H:%M:%SZ)"
        echo "docker      $(docker info --format '{{.ServerVersion}}, {{.OperatingSystem}}, {{.NCPU}} CPUs, {{.KernelVersion}}')"
        echo "php         $(php_exec php -r 'echo PHP_VERSION, ", phpredis ", phpversion("redis"), ", APCu ", phpversion("apcu"), ", ext-zstd ", phpversion("zstd");')"
        echo "redis       $(docker exec "$REDIS" redis-server --version)"
        echo "toxiproxy   $TOXIPROXY_IMAGE"
        php_exec sh -c 'composer show 2>/dev/null | grep -E "^(symfony/cache|symfony/cache-contracts|artigo/cache-stampede) " | sed "s/^/package     /"'
        echo "this copy   $(cd "$ROOT" && git describe --tags --always --dirty 2>/dev/null || echo unknown)"
    } | tee "$OUT/environment.txt"

    # the aggregator: every run of a benchmark, line by line, each number
    # turned into min / median / max where the runs disagree
    docker exec -i "$PHP" sh -c 'cat > /work/aggregate.php' <<'PHPCODE'
<?php

declare(strict_types=1);

// usage: php aggregate.php <label> <run file>...
$label = $argv[1];
$runs = [];

foreach (array_slice($argv, 2) as $file) {
    $seen = [];
    $lines = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        // a line is known by its words, its numbers taken out, and by how
        // often those words came before in the same run
        $template = preg_replace('/\d[\d,]*(?:\.\d+)?/', '#', $line);
        $key = $template."\0".($seen[$template] = ($seen[$template] ?? 0) + 1);
        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $line, $numbers);
        $lines[$key] = [$template, $numbers[0]];
    }

    $runs[] = $lines;
}

echo PHP_EOL.'## '.$label.' ('.count($runs).' runs: median, min-max where they differ)'.PHP_EOL.PHP_EOL;

foreach ($runs[0] ?? [] as $key => [$template, $numbers]) {
    $parts = explode('#', $template);
    $out = $parts[0];

    foreach ($numbers as $i => $first) {
        $values = [];

        foreach ($runs as $run) {
            if (isset($run[$key][1][$i])) {
                $values[] = $run[$key][1][$i];
            }
        }

        $parsed = array_map(static fn (string $v): float => (float) str_replace(',', '', $v), $values);
        $order = $parsed;
        asort($order);
        $sorted = array_keys($order);
        $median = $values[$sorted[intdiv(count($sorted), 2)]];
        $out .= min($parsed) === max($parsed)
            ? $first
            : sprintf('%s [%s-%s]', $median, $values[$sorted[0]], $values[$sorted[count($sorted) - 1]]);
        $out .= $parts[$i + 1] ?? '';
    }

    echo $out.PHP_EOL;
}
PHPCODE
}

# measure <key> <label> <name> <php arguments>...: RUNS runs, the host load
# logged before each, then the summary
measure() {
    local key=$1 label=$2 name=$3
    shift 3
    wanted "$key" || return 0

    local files=()

    for run in $(seq 1 "$RUNS"); do
        local file="/work/out/$name-$run.txt"
        echo "[$name] run $run/$RUNS, load $(docker exec "$PHP" cat /proc/loadavg)" | tee -a "$OUT/load.txt"
        php_exec sh -c "mkdir -p /work/out && php $* > $file 2>&1" || echo "[$name] run $run exited non-zero, see $name-$run.txt" >&2
        # through a pipe rather than docker cp, which would take a Git Bash
        # path on Windows for one of its own
        docker exec "$PHP" cat "$file" > "$OUT/$name-$run.txt"
        files+=("$file")
    done

    php_exec php /work/aggregate.php "$label" "${files[@]}" | tee -a "$OUT/summary.md"
}

# request.php through toxiproxy: the proxy is created on first use
through_proxy() {
    echo "dsn=redis://$PROXY:6397 proxy=http://$PROXY:8474 upstream=$BEHIND:6379"
}

main() {
    setup

    local apcu='-d apc.enable_cli=1'
    local direct="dsn=redis://$REDIS:6379"

    measure headline 'measurements.md#measured-not-asserted, README "Measured, not asserted" (times)' headline \
        "$apcu benchmarks/bench.php $direct items=20000"
    measure unique 'measurements.md#with-a-tag-of-its-own-on-every-item (times)' unique \
        "$apcu benchmarks/bench.php $direct items=20000 unique=1"
    measure behind 'measurements.md#the-rule-set-a-fresh-process-adopts, behind=100000 table (times)' behind \
        "$apcu benchmarks/bench.php $direct items=20000 unique=1 behind=100000"
    measure adopt 'measurements.md#the-rule-set-a-fresh-process-adopts, README "The bound and its floor"' adopt \
        "$apcu -d apc.shm_size=256M benchmarks/bench.php $direct adopt=15000,100000"
    measure guard 'guard.md "What it costs" (times)' guard \
        "$apcu benchmarks/bench.php $direct guard=1"

    measure ban 'measurements.md#banning-thousands-of-tags, standalone 1 000 x 1 000 (duration, PING)' ban-1000x1000 \
        "benchmarks/ban.php $direct tags=1000 entries=1000"
    measure ban 'measurements.md#banning-thousands-of-tags, standalone 5 000 x 200 (duration, PING)' ban-5000x200 \
        "benchmarks/ban.php $direct tags=5000 entries=200"
    measure ban-cluster 'measurements.md#banning-thousands-of-tags, cluster 1 000 x 200 (duration, PING)' ban-cluster-1000x200 \
        "benchmarks/ban.php 'dsn=$CLUSTER_DSN' tags=1000 entries=200 stores=versioned,symfony,tagaware,tagaware-slot"
    measure ban-cluster 'measurements.md#banning-thousands-of-tags, cluster 5 000 x 40 (duration, PING)' ban-cluster-5000x40 \
        "benchmarks/ban.php 'dsn=$CLUSTER_DSN' tags=5000 entries=40 stores=versioned,symfony,tagaware,tagaware-slot"
    measure onecall 'measurements.md "What does a loop of invalidations cost?": ten thousand tags in one invalidateTags() call' onecall \
        "benchmarks/ban.php $direct tags=10000 entries=1 chunk=10000 stores=versioned"

    for behind in 2000 10000; do
        for pattern in loop batch; do
            measure request "measurements.md#what-a-request-pays-in-time, $pattern, $behind invalidations, same machine" "request-$pattern-$behind-direct" \
                "$apcu benchmarks/request.php $direct pattern=$pattern behind=$behind"
            for latency in 0 1; do
                measure request "measurements.md#what-a-request-pays-in-time, $pattern, $behind invalidations, proxy latency=$latency" "request-$pattern-$behind-proxy$latency" \
                    "$apcu benchmarks/request.php $(through_proxy) latency=$latency pattern=$pattern behind=$behind"
            done
        done
    done

    measure request 'measurements.md#what-a-request-pays-in-time, a hundred reads one by one, data centre' request-reads100 \
        "$apcu benchmarks/request.php $(through_proxy) latency=0 reads=100 stores=tagaware,versioned"
    measure request 'measurements.md#what-a-request-pays-in-time, 18 list tags per item, data centre' request-shared18 \
        "$apcu benchmarks/request.php $(through_proxy) latency=0 shared=18 stores=tagaware,versioned"
    # one GET per request through a plain RedisAdapter: the round trip of each link
    measure request 'measurements.md#what-a-request-pays-in-time, the round trip: same machine' rtt-direct \
        "benchmarks/request.php $direct reads=1 requests=2000 stores=redis"
    for latency in 0 1; do
        measure request "measurements.md#what-a-request-pays-in-time, the round trip: proxy latency=$latency" "rtt-proxy$latency" \
            "benchmarks/request.php $(through_proxy) latency=$latency reads=1 requests=2000 stores=redis"
    done

    measure marshaller 'zstd-marshaller.md, README "ZstdMarshaller" (times; the ratios are bytes)' marshaller \
        "benchmarks/marshaller.php"
    measure soft 'soft-invalidation.md, README "Soft invalidation" (waits)' soft \
        "benchmarks/soft.php $direct"

    echo
    echo "summary: $OUT/summary.md"
}

main "$@"
