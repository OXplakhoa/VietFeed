#!/usr/bin/env bash
# Idempotent setup / reset of the VietFeed DEMO stores only (Mongo vietfeed_demo, Cassandra vietfeed_demo,
# Redis DB 5/6/7). Run from the demo checkout (~/vietfeed-demo) on the owner PC:
#
#   deploy/demo-reset.sh                    # create/refresh everything (safe to rerun)
#   deploy/demo-reset.sh --with-brief       # also generate AI briefs (needs AI_PROVIDER != none)
#   deploy/demo-reset.sh --reset-passwords  # re-hash the three demo account passwords
#   deploy/demo-reset.sh --wipe             # FIRST drop the demo stores (interactive confirmation), then rebuild
#
# Safety: the loaded Laravel config (read through `artisan tinker`, never by parsing .env) must point at the demo
# names, otherwise the script refuses before touching anything. It never deletes Neo4j data (Community edition has
# a single shared database; see deploy/README.md). Passwords are never printed.
set -euo pipefail

DEMO_MONGO_DB="vietfeed_demo"
DEMO_CASSANDRA_KEYSPACE="vietfeed_demo"
DEMO_REDIS_DEFAULT=5
DEMO_REDIS_CACHE=6
DEMO_REDIS_NOSQL=7
CREDENTIALS_FILE="${DEMO_CREDENTIALS_FILE:-$HOME/.vietfeed-demo-credentials}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
[ -f artisan ] || { echo "artisan not found in $ROOT" >&2; exit 1; }

WIPE=0 WITH_BRIEF=0 RESET_PASSWORDS=0
for arg in "$@"; do
  case "$arg" in
    --wipe) WIPE=1 ;;
    --with-brief) WITH_BRIEF=1 ;;
    --reset-passwords) RESET_PASSWORDS=1 ;;
    -h|--help) sed -n '2,13p' "$0"; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

art() { php artisan "$@" </dev/null; }
step() { printf '\n==> %s\n' "$*"; }

tinker() { # tinker <php code> ; extra env is inherited
  php artisan tinker --execute="$1" </dev/null 2>&1
}

# --- 1. Guard: refuse unless the loaded env is the demo env ----------------------------------------------------
GUARD_PHP=$(cat <<PHP
\$bad = [];
\$chk = function (string \$what, bool \$ok) use (&\$bad) { if (! \$ok) { \$bad[] = \$what; } };
\$redis = fn (string \$c) => \Illuminate\Support\Facades\Redis::connection(\$c)->client()->getDbNum();
\$chk('MONGO_DB', config('database.connections.mongodb.database') === '$DEMO_MONGO_DB' && config('nosql.mongo.database') === '$DEMO_MONGO_DB');
\$chk('CASSANDRA_KEYSPACE', config('nosql.cassandra.keyspace') === '$DEMO_CASSANDRA_KEYSPACE');
\$chk('redis default DB', \$redis('default') === $DEMO_REDIS_DEFAULT);
\$chk('redis cache DB', \$redis('cache') === $DEMO_REDIS_CACHE);
\$chk('redis nosql DB', \$redis('nosql') === $DEMO_REDIS_NOSQL);
\$chk('dedicated demo redis port', (int) config('database.redis.default.port') === 6380 && (int) config('database.redis.cache.port') === 6380 && (int) config('database.redis.nosql.port') === 6380);
\$chk('session on default conn', config('session.driver') === 'redis' && config('session.connection') === 'default');
\$chk('queue on default conn', config('queue.default') === 'redis' && config('queue.connections.redis.connection') === 'default');
\$chk('cache on cache conn', config('cache.default') === 'redis' && config('cache.stores.redis.connection') === 'cache');
echo \$bad ? 'GUARD_FAIL: '.implode(', ', \$bad) : 'GUARD_OK';
PHP
)

guard() {
  local out
  out="$(tinker "$GUARD_PHP" | grep -E '^GUARD_(OK|FAIL)' || true)"
  if [ "$out" != "GUARD_OK" ]; then
    echo "REFUSED: this environment is not the demo environment (${out:-no guard answer; are the stores up and the config valid?})." >&2
    echo "Expected REDIS_PORT=6380 MONGO_DB=$DEMO_MONGO_DB CASSANDRA_KEYSPACE=$DEMO_CASSANDRA_KEYSPACE REDIS_DB=$DEMO_REDIS_DEFAULT REDIS_CACHE_DB=$DEMO_REDIS_CACHE REDIS_NOSQL_DB=$DEMO_REDIS_NOSQL." >&2
    exit 1
  fi
  echo "guard: demo stores confirmed (mongo=$DEMO_MONGO_DB, cassandra=$DEMO_CASSANDRA_KEYSPACE, redis=$DEMO_REDIS_DEFAULT/$DEMO_REDIS_CACHE/$DEMO_REDIS_NOSQL)"
}

step "Clear cached config so the guard sees the live env"
art config:clear >/dev/null
guard

# --- 2. Optional wipe (demo stores only) -----------------------------------------------------------------------
if [ "$WIPE" -eq 1 ]; then
  [ -t 0 ] || { echo "--wipe needs an interactive terminal." >&2; exit 1; }
  echo
  echo "WIPE will DROP: Mongo database '$DEMO_MONGO_DB', Redis DB $DEMO_REDIS_DEFAULT/$DEMO_REDIS_CACHE/$DEMO_REDIS_NOSQL, Cassandra keyspace '$DEMO_CASSANDRA_KEYSPACE'."
  echo "Neo4j is NOT touched (shared single database). Stop the queue/scheduler units first:"
  echo "  sudo systemctl stop vietfeed-demo-queue vietfeed-demo-scheduler"
  read -r -p "Type exactly 'wipe $DEMO_MONGO_DB' to continue: " answer
  [ "$answer" = "wipe $DEMO_MONGO_DB" ] || { echo "Aborted."; exit 1; }
  WIPE_PHP=$(cat <<PHP
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
\$mongo = DB::connection('mongodb');
if (\$mongo->getDatabaseName() !== '$DEMO_MONGO_DB') { throw new RuntimeException('mongo guard'); }
\$cql = app(\App\StoryProcessing\CassandraClient::class);
if (\$cql->keyspace() !== '$DEMO_CASSANDRA_KEYSPACE') { throw new RuntimeException('cassandra guard'); }
\$targets = ['default' => $DEMO_REDIS_DEFAULT, 'cache' => $DEMO_REDIS_CACHE, 'nosql' => $DEMO_REDIS_NOSQL];
foreach (\$targets as \$name => \$db) {
    if (Redis::connection(\$name)->client()->getDbNum() !== \$db) { throw new RuntimeException("redis guard \$name"); }
}
foreach (\$targets as \$name => \$db) { Redis::connection(\$name)->client()->flushDB(); echo "WIPED redis \$db\n"; }
\$mongo->getMongoDB()->drop(); echo "WIPED mongo $DEMO_MONGO_DB\n";
\$cql->execute('DROP KEYSPACE IF EXISTS $DEMO_CASSANDRA_KEYSPACE'); echo "WIPED cassandra $DEMO_CASSANDRA_KEYSPACE\n";
PHP
)
  out="$(tinker "$WIPE_PHP")"
  echo "$out" | grep -E '^WIPED' || { echo "Wipe did not complete:" >&2; echo "$out" | tail -5 >&2; exit 1; }
fi

# --- 3. Schemas and indexes ------------------------------------------------------------------------------------
step "Mongo indexes (migrate)"
art migrate --force
step "Cassandra / Redis schemas"
art storylens schema
art interactions schema
art reading-pass schema
art sources:history-manage bootstrap
art moderation:audit schema
art rbac:audit schema

# --- 4. Seed configuration -------------------------------------------------------------------------------------
step "Seed categories and default sources (committed seeders on main)"
art db:seed --class=CategorySeeder --force
art db:seed --class=SourceSeeder --force

# --- 5. StoryLens ----------------------------------------------------------------------------------------------
step "StoryLens ingest and projections"
art storylens ingest
for _ in 1 2 3; do
  art storylens consume --section=timeline --consumer=demo-reset --limit=100
  art storylens consume --section=graph --consumer=demo-reset --limit=100
done

if [ "$WITH_BRIEF" -eq 1 ]; then
  step "AI briefs (--with-brief)"
  provider="$(tinker "echo 'PROVIDER='.config('ai.provider');" | grep -E '^PROVIDER=' | head -1 | cut -d= -f2)"
  if [ -z "$provider" ] || [ "$provider" = "none" ]; then
    echo "AI_PROVIDER is '${provider:-unknown}': skipping brief generation."
  else
    art storylens brief --all --sync
  fi
fi

# --- 6. Synthetic workload -------------------------------------------------------------------------------------
# demo:synthetic refuses APP_ENV=production on purpose. This single command runs with a process-local APP_ENV=demo
# (the stores were already proven to be the demo stores by the guard above); nothing else changes.
step "Synthetic demo workload (labelled synthetic readers/events)"
env APP_ENV=demo php artisan demo:synthetic seed </dev/null
for _ in 1 2 3; do
  art interactions consume --consumer=demo-reset --limit=100
done

# --- 7. Demo accounts ------------------------------------------------------------------------------------------
step "Demo accounts"
gen_pw() { head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20 || true; }
# Order: (1) stored file, (2) explicit environment variables override it, (3) generate whatever is still missing.
# Passwords are re-hashed into Mongo (force reset) whenever the file was absent, a password was supplied through the
# environment, or a password was generated in this run, so the stored/supplied value always works.
ENV_READER="${DEMO_READER_PASSWORD:-}" ENV_ADMIN="${DEMO_ADMIN_PASSWORD:-}" ENV_SUPER="${DEMO_SUPERADMIN_PASSWORD:-}"
unset DEMO_READER_PASSWORD DEMO_ADMIN_PASSWORD DEMO_SUPERADMIN_PASSWORD
FORCE_PW="$RESET_PASSWORDS"
if [ -f "$CREDENTIALS_FILE" ]; then
  # shellcheck disable=SC1090
  . "$CREDENTIALS_FILE"
else
  FORCE_PW=1
fi
[ -n "$ENV_READER" ] && { DEMO_READER_PASSWORD="$ENV_READER"; FORCE_PW=1; }
[ -n "$ENV_ADMIN" ] && { DEMO_ADMIN_PASSWORD="$ENV_ADMIN"; FORCE_PW=1; }
[ -n "$ENV_SUPER" ] && { DEMO_SUPERADMIN_PASSWORD="$ENV_SUPER"; FORCE_PW=1; }
[ "$RESET_PASSWORDS" -eq 1 ] && { DEMO_READER_PASSWORD=""; DEMO_ADMIN_PASSWORD=""; DEMO_SUPERADMIN_PASSWORD=""; }
for var in DEMO_READER_PASSWORD DEMO_ADMIN_PASSWORD DEMO_SUPERADMIN_PASSWORD; do
  if [ -z "${!var:-}" ]; then printf -v "$var" '%s' "$(gen_pw)"; FORCE_PW=1; fi
done
(
  umask 077
  {
    echo "# VietFeed demo accounts. Keep private (mode 600). Generated by deploy/demo-reset.sh."
    echo "DEMO_READER_PASSWORD='$DEMO_READER_PASSWORD'"
    echo "DEMO_ADMIN_PASSWORD='$DEMO_ADMIN_PASSWORD'"
    echo "DEMO_SUPERADMIN_PASSWORD='$DEMO_SUPERADMIN_PASSWORD'"
  } >"$CREDENTIALS_FILE"
)
chmod 600 "$CREDENTIALS_FILE"
echo "Passwords stored in $CREDENTIALS_FILE (mode 600, not printed)."
export DEMO_READER_PASSWORD DEMO_ADMIN_PASSWORD DEMO_SUPERADMIN_PASSWORD
export DEMO_RESET_PASSWORDS="$FORCE_PW"

ACCOUNTS_PHP=$(cat <<'PHP'
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

$reset = getenv('DEMO_RESET_PASSWORDS') === '1';
$make = function (string $email, string $name, string $password) use ($reset): User {
    $user = User::where('email', $email)->first();
    if ($user === null) {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'email_verified_at' => now()]);
        echo "ACCT: created $email\n";
    } elseif ($reset) {
        $user->forceFill(['password' => Hash::make($password)])->save();
        echo "ACCT: password reset $email\n";
    } else {
        echo "ACCT: exists $email\n";
    }

    return $user;
};

try {
    $make('reader@vietfeed.app', 'Demo Reader', getenv('DEMO_READER_PASSWORD'));
    $make('admin@vietfeed.app', 'Demo Admin (limited)', getenv('DEMO_ADMIN_PASSWORD'));
    $make('superadmin@vietfeed.app', 'Demo Super Admin', getenv('DEMO_SUPERADMIN_PASSWORD'));

    $code = Artisan::call('rbac:bootstrap', ['--super-admin' => 'superadmin@vietfeed.app']);
    echo 'ACCT: rbac:bootstrap exit '.$code."\n";

    $super = User::where('email', 'superadmin@vietfeed.app')->first();
    $admin = User::where('email', 'admin@vietfeed.app')->first();
    if ($super->isSuperAdmin() && $admin->role !== 'admin') {
        app(\App\Authorization\UserAuthorization::class)->edit(
            $super, $admin, ['name' => $admin->name, 'email' => $admin->email],
            'admin', ['content.manage', 'comments.manage', 'stories.review'],
        );
        echo "ACCT: admin@vietfeed.app promoted with content.manage, comments.manage, stories.review\n";
    }
    echo 'ACCT: super_admin='.($super->fresh()->isSuperAdmin() ? 'yes' : 'NO').' admin_role='.$admin->fresh()->role."\n";
} catch (Throwable $e) {
    echo 'ACCT: FAILED '.get_class($e).': '.$e->getMessage()."\n";
}
PHP
)
acct_out="$(tinker "$ACCOUNTS_PHP" | grep -E '^ACCT:' || true)"
echo "$acct_out"
if echo "$acct_out" | grep -q 'FAILED' || ! echo "$acct_out" | grep -q 'super_admin=yes admin_role=admin'; then
  echo "Account setup did not finish cleanly (see above)." >&2
  exit 1
fi
unset DEMO_READER_PASSWORD DEMO_ADMIN_PASSWORD DEMO_SUPERADMIN_PASSWORD

step "Done"
echo "Run 'php artisan optimize' (or the update procedure in deploy/README.md) to re-cache config, then reload php-fpm."
