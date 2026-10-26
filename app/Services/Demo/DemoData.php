<?php

namespace App\Services\Demo;

use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\DemoGuard;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Demo data an administrator loads to learn the system and removes again (D94), only from
 * Admin › Demo data (or `php artisan demo:data`).
 *
 * Loading runs the DemoSeeder in sandbox mode inside one transaction, so it lands completely or not
 * at all. Every row it created is recorded (by primary key), and so is the original of any existing
 * row it changed. Removal deletes those rows plus everything later added inside the demo
 * businesses, follows foreign keys and polymorphic links outward, and never deletes real data: a real
 * row that points at demo data is unlinked when it can be, otherwise the demo row it needs is kept
 * and reported.
 */
class DemoData
{
    /** Tables that are never recorded: framework plumbing, counters and our own records. */
    public const SKIP = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
        'password_reset_tokens', 'call_id_sequences', 'invoice_sequences', 'demo_data_rows', 'demo_data_changes'];

    /** Links that aren't declared as foreign keys: [child table, column, parent table, parent column]. */
    private const EXTRA_LINKS = [
        ['agent_calendar_events', 'shift_id', 'agent_duty_schedules', 'id'],
        ['sessions', 'user_id', 'users', 'id'],
    ];

    /** Rows that only link a real business to a demo person or course: removed with the demo. */
    private const LINK_TABLES = ['agent_assignments', 'organization_user', 'training_assignments', 'training_rules', 'notification_preferences'];

    private const STUCK_AFTER_MINUTES = 30;

    private bool $tracking = false;

    /** @var array<string, int> highest id per table before the load */
    private array $marks = [];

    /** @var array<string, array<string, true>> keys created in tables without an incrementing id */
    private array $created = [];

    /** @var array<string, array<string, array{type: string, before: array<string, mixed>, after: ?array<string, mixed>}>> */
    private array $changes = [];

    private static bool $listening = false;

    public function __construct(private Audit $audit) {}

    /**
     * none | loading | loaded | failed | removing | failed_removal, with who and when.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $state = rescue(fn () => PlatformSetting::get(DemoGuard::STATE_SETTING), null, report: false);
        $state = is_array($state) ? $state : ['status' => 'none'];
        $busy = in_array($state['status'], ['loading', 'removing'], true);
        $state['stuck'] = $busy && isset($state['started_at']) && Carbon::parse($state['started_at'])->diffInMinutes(now()) >= self::STUCK_AFTER_MINUTES;

        return $state;
    }

    /** Mark the work as started; false when something else is already running or it doesn't apply. */
    public function begin(string $action, User $by): bool
    {
        return (bool) Cache::lock('demo-data', 10)->get(function () use ($action, $by) {
            $status = $this->state()['status'];
            $allowed = $action === 'load' ? in_array($status, ['none', 'failed'], true) : in_array($status, ['loaded', 'failed_removal'], true);
            if (! $allowed) {
                return false;
            }
            $this->saveState([...$this->state(), 'status' => $action === 'load' ? 'loading' : 'removing', 'started_at' => now()->toIso8601String(),
                'started_by' => $by->name, 'error' => null]);

            return true;
        });
    }

    /** After a crash mid-way: decide from the database what's really there. */
    public function recover(User $by): string
    {
        $state = $this->state();
        if (! $state['stuck']) {
            return $state['status'];
        }
        $present = DB::table('demo_data_rows')->exists() || Organization::withoutGlobalScopes()->where('is_demo', true)->exists();
        $status = $present ? 'loaded' : 'none';
        $this->saveState([...$state, 'status' => $status, 'error' => 'The last '.($state['status'] === 'loading' ? 'load' : 'removal').' stopped before it finished.']);
        $this->audit->record('demo.recovered', null, ['status' => $state['status']], ['status' => $status], actor: $by, label: 'Demo data');

        return $status;
    }

    /**
     * Load the demo (call {@see begin()} first). Runs to the end even if the browser leaves.
     *
     * @return array<string, int> rows created per table
     */
    public function load(User $by): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $keys = ['mail.default', 'queue.default', 'broadcasting.default', 'ai.anthropic.api_key', 'tenancy.auto_assign_agents'];
        $config = array_combine($keys, array_map(fn (string $k) => config($k), $keys));
        $files = [];

        try {
            $this->listen();
            DB::connection()->setTransactionManager(new ImmediateCallbacks);
            $summary = DB::transaction(function () use ($by, &$files) {
                $tables = $this->tables();
                $this->marks = [];
                foreach ($tables as $table => $meta) {
                    if ($meta['incrementing']) {
                        $this->marks[$table] = (int) DB::table($table)->max('id');
                    }
                }
                $this->created = $this->changes = [];

                $this->tracking = true;
                try {
                    $seeder = new DemoSeeder(sandbox: true);
                    $seeder->setContainer(app());
                    $seeder->run();
                    $files = $seeder->files;
                } finally {
                    $this->tracking = false;
                }

                $summary = $this->record($tables);
                $orgs = $this->createdIds('organizations');
                $users = $this->createdIds('users');
                Organization::withoutGlobalScopes()->whereIn('id', $orgs)->update(['is_demo' => true]);
                User::query()->whereIn('id', $users)->update(['is_demo' => true]);

                $this->saveState(['status' => 'loaded', 'loaded_at' => now()->toIso8601String(), 'loaded_by' => $by->name, 'files' => $files,
                    'summary' => ['businesses' => count($orgs), 'people' => count($users), 'rows' => array_sum($summary)], 'error' => null]);
                $this->audit->record('demo.loaded', null, new: ['businesses' => count($orgs), 'rows' => array_sum($summary)], actor: $by, label: 'Demo data');

                return $summary;
            });
        } catch (Throwable $e) {
            report($e);
            foreach ($files as $path) {
                Storage::disk('local')->delete($path);
            }
            $this->saveState(['status' => 'failed', 'failed_at' => now()->toIso8601String(), 'error' => Str::limit($e->getMessage(), 500)]);

            throw $e;
        } finally {
            DB::connection()->setTransactionManager(app('db.transactions'));
            config($config);
            Http::preventStrayRequests(false);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $summary;
    }

    /**
     * Remove the demo (call {@see begin()} first).
     *
     * @return array{deleted: array<string, int>, unlinked: int, kept: array<string, int>, restored: int}
     */
    public function remove(User $by): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $state = $this->state();

        try {
            $result = DB::transaction(function () use ($by) {
                $tables = $this->tables();
                $doomed = $this->recorded($tables);
                $orgs = array_map('intval', array_keys($doomed['organizations'] ?? []));
                $orgs = array_values(array_unique([...$orgs, ...Organization::withoutGlobalScopes()->where('is_demo', true)->pluck('id')->all()]));
                foreach ($orgs as $id) {
                    $doomed['organizations'][(string) $id] = true;
                }
                foreach (User::query()->where('is_demo', true)->pluck('id') as $id) {
                    $doomed['users'][(string) $id] = true;
                }
                // Whatever was added inside a demo business since, by anyone, goes with it.
                foreach ($tables as $table => $meta) {
                    if ($meta['organization'] && $meta['pk'] !== [] && $orgs !== []) {
                        foreach (array_chunk($orgs, 500) as $chunk) {
                            foreach (DB::table($table)->whereIn('organization_id', $chunk)->get($meta['pk']) as $row) {
                                $doomed[$table][$this->key($row, $meta['pk'])] = true;
                            }
                        }
                    }
                }

                [$doomed, $unlink, $kept] = $this->close($tables, $doomed, $orgs);
                $restored = $this->restore($tables, $doomed);
                $deleted = $this->delete($tables, $doomed, $unlink);
                $this->rewindInvoiceNumbers();

                DB::table('demo_data_rows')->delete();
                DB::table('demo_data_changes')->delete();
                $result = ['deleted' => $deleted, 'unlinked' => array_sum(array_map(fn ($cols) => array_sum(array_map('count', $cols)), $unlink)),
                    'kept' => array_map('count', $kept), 'restored' => $restored];
                $this->saveState(['status' => 'none', 'removed_at' => now()->toIso8601String(), 'removed_by' => $by->name,
                    'last_removal' => ['rows' => array_sum($deleted), 'kept' => array_sum($result['kept']), 'kept_tables' => $result['kept']]]);
                $this->audit->record('demo.removed', null, old: ['rows' => array_sum($deleted)], new: ['kept' => $result['kept'], 'restored' => $restored], actor: $by, label: 'Demo data');

                return $result;
            });
        } catch (Throwable $e) {
            report($e);
            $this->saveState([...$state, 'status' => 'failed_removal', 'error' => Str::limit($e->getMessage(), 500)]);

            throw $e;
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $disk = Storage::disk('local');
        foreach ($state['files'] ?? [] as $path) {
            $disk->delete($path);
        }
        foreach (array_unique(array_map('dirname', $state['files'] ?? [])) as $folder) {
            if ($folder !== '.' && $disk->allFiles($folder) === []) {
                $disk->deleteDirectory($folder);
            }
        }

        return $result;
    }

    // ---------------------------------------------------------------------------------------------
    // Recording the load
    // ---------------------------------------------------------------------------------------------

    private function listen(): void
    {
        if (self::$listening) {
            return;
        }
        self::$listening = true;
        // One listener for the process; it only acts while a load is running.
        Event::listen('eloquent.created: *', function (string $event, array $payload) {
            $model = $payload[0] ?? null;
            if ($this->tracking && $model instanceof Model && ! in_array($model->getTable(), self::SKIP, true) && ! $this->isOurState($model)) {
                $this->created[$model->getTable()][(string) $model->getKey()] = true;
            }
        });
        Event::listen('eloquent.updated: *', function (string $event, array $payload) {
            $model = $payload[0] ?? null;
            if ($this->tracking && $model instanceof Model && $this->existedBefore($model)) {
                $key = (string) $model->getKey();
                $changed = array_keys($model->getChanges());
                $entry = $this->changes[$model->getTable()][$key] ?? ['type' => 'updated', 'before' => [], 'after' => []];
                foreach ($changed as $column) {
                    $entry['before'] += [$column => $model->getRawOriginal($column)];
                    $entry['after'][$column] = $model->getAttributes()[$column] ?? null;
                }
                $this->changes[$model->getTable()][$key] = $entry;
            }
        });
        Event::listen('eloquent.deleted: *', function (string $event, array $payload) {
            $model = $payload[0] ?? null;
            if ($this->tracking && $model instanceof Model && $this->existedBefore($model)) {
                $before = $this->changes[$model->getTable()][(string) $model->getKey()]['before'] ?? [];
                $this->changes[$model->getTable()][(string) $model->getKey()] = ['type' => 'deleted', 'before' => $before + $model->getRawOriginal(), 'after' => null];
            }
        });
    }

    private function isOurState(Model $model): bool
    {
        return $model instanceof PlatformSetting && $model->getKey() === DemoGuard::STATE_SETTING;
    }

    private function existedBefore(Model $model): bool
    {
        $table = $model->getTable();
        if (in_array($table, self::SKIP, true) || $this->isOurState($model)) {
            return false;
        }
        if (isset($this->marks[$table])) {
            return is_numeric($model->getKey()) && (int) $model->getKey() <= $this->marks[$table];
        }

        return ! isset($this->created[$table][(string) $model->getKey()]);
    }

    /**
     * @param  array<string, array{pk: list<string>, incrementing: bool, organization: bool}>  $tables
     * @return array<string, int>
     */
    private function record(array $tables): array
    {
        $summary = [];
        $now = now();
        foreach ($tables as $table => $meta) {
            $ranges = null;
            $keys = null;
            if ($meta['incrementing']) {
                // Inside this transaction only our own new rows are visible above the mark.
                $ids = DB::table($table)->where('id', '>', $this->marks[$table])->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
                $ranges = $ids === [] ? null : $this->ranges($ids);
                $count = count($ids);
            } else {
                $keys = array_keys($this->created[$table] ?? []);
                $count = count($keys);
                $keys = $keys === [] ? null : $keys;
            }
            if ($count > 0) {
                DB::table('demo_data_rows')->insert(['table_name' => $table, 'ranges' => $ranges ? json_encode($ranges) : null,
                    'keys' => $keys ? json_encode($keys) : null, 'created_at' => $now, 'updated_at' => $now]);
                $summary[$table] = $count;
            }
        }
        foreach ($this->changes as $table => $rows) {
            foreach ($rows as $key => $change) {
                DB::table('demo_data_changes')->insert(['table_name' => $table, 'record_key' => $key, 'type' => $change['type'],
                    'before' => json_encode($change['before']), 'after' => $change['after'] === null ? null : json_encode($change['after']), 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        return $summary;
    }

    /** @return list<int> */
    private function createdIds(string $table): array
    {
        $row = DB::table('demo_data_rows')->where('table_name', $table)->first();

        return $row ? $this->expand(json_decode((string) $row->ranges, true) ?: []) : [];
    }

    /**
     * @param  list<int>  $ids  sorted
     * @return list<array{0: int, 1: int}>
     */
    private function ranges(array $ids): array
    {
        $ranges = [];
        foreach ($ids as $id) {
            $last = array_key_last($ranges);
            if ($last !== null && $ranges[$last][1] === $id - 1) {
                $ranges[$last][1] = $id;
            } else {
                $ranges[] = [$id, $id];
            }
        }

        return $ranges;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     * @return list<int>
     */
    private function expand(array $ranges): array
    {
        $ids = [];
        foreach ($ranges as [$from, $to]) {
            array_push($ids, ...range($from, $to));
        }

        return $ids;
    }

    // ---------------------------------------------------------------------------------------------
    // Removing it
    // ---------------------------------------------------------------------------------------------

    /**
     * Recorded rows that still exist, as table => [key => true].
     *
     * @param  array<string, array{pk: list<string>, incrementing: bool, organization: bool}>  $tables
     * @return array<string, array<string, true>>
     */
    private function recorded(array $tables): array
    {
        $doomed = [];
        foreach (DB::table('demo_data_rows')->get() as $row) {
            if (! isset($tables[$row->table_name])) {
                continue;
            }
            $keys = $row->ranges ? $this->expand(json_decode((string) $row->ranges, true)) : (json_decode((string) $row->keys, true) ?: []);
            foreach ($keys as $key) {
                $doomed[$row->table_name][(string) $key] = true;
            }
        }

        return $doomed;
    }

    /**
     * Follow every link to the rows being removed until nothing changes.
     *
     * @param  array<string, array{pk: list<string>, incrementing: bool, organization: bool, nullable: array<string, bool>}>  $tables
     * @param  array<string, array<string, true>>  $doomed
     * @param  list<int>  $orgs  demo organisations
     * @return array{0: array<string, array<string, true>>, 1: array<string, array<string, array<string, true>>>, 2: array<string, array<string, true>>}
     */
    private function close(array $tables, array $doomed, array $orgs): array
    {
        $links = $this->links($tables);
        $morphs = $this->morphs($tables);
        $unlink = [];   // table => column => [key => true]
        $kept = [];     // table => [key => true]: demo rows real data still needs
        $demoOrg = array_flip($orgs);

        do {
            $changed = false;
            foreach ($links as [$child, $column, $parent, $parentColumn]) {
                if (empty($doomed[$parent]) || ! isset($tables[$child])) {
                    continue;
                }
                $childMeta = $tables[$child];
                // Referenced values => the parent's key.
                $values = $this->values($parent, $tables[$parent]['pk'], $parentColumn, array_keys($doomed[$parent]));
                foreach (array_chunk(array_keys($values), 500) as $chunk) {
                    $select = array_values(array_unique([...$childMeta['pk'], $column, ...($childMeta['organization'] ? ['organization_id'] : [])]));
                    $rows = $childMeta['pk'] === [] ? collect() : DB::table($child)->whereIn($column, $chunk)->get($select);
                    foreach ($rows as $row) {
                        $key = $this->key($row, $childMeta['pk']);
                        if (isset($doomed[$child][$key])) {
                            continue;
                        }
                        if ($childMeta['nullable'][$column] ?? false) {
                            $unlink[$child][$column][$key] = true;

                            continue;
                        }
                        $realTenant = $childMeta['organization'] && $row->organization_id !== null && ! isset($demoOrg[(int) $row->organization_id]);
                        if (isset($kept[$child][$key]) || ($realTenant && ! in_array($child, self::LINK_TABLES, true))) {
                            // Real data needs this demo row: keep it rather than lose theirs.
                            $parentKey = $values[(string) $row->{$column}];
                            unset($doomed[$parent][$parentKey]);
                            $kept[$parent][$parentKey] = true;
                            $changed = true;

                            continue;
                        }
                        $doomed[$child][$key] = true;
                        $changed = true;
                    }
                }
            }
            foreach ($morphs as [$table, $type, $id]) {
                foreach (DB::table($table)->distinct()->pluck($type) as $alias) {
                    $class = Relation::getMorphedModel((string) $alias) ?? (string) $alias;
                    if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                        continue;
                    }
                    $target = (new $class)->getTable();
                    if (empty($doomed[$target]) || $tables[$table]['pk'] === []) {
                        continue;
                    }
                    foreach (array_chunk(array_keys($doomed[$target]), 500) as $chunk) {
                        foreach (DB::table($table)->where($type, $alias)->whereIn($id, $chunk)->get($tables[$table]['pk']) as $row) {
                            $key = $this->key($row, $tables[$table]['pk']);
                            if (! isset($doomed[$table][$key]) && ! isset($kept[$table][$key])) {
                                $doomed[$table][$key] = true;
                                $changed = true;
                            }
                        }
                    }
                }
            }
        } while ($changed);

        foreach ($unlink as $table => $columns) {
            foreach ($columns as $column => $keys) {
                $unlink[$table][$column] = array_diff_key($keys, $doomed[$table] ?? []);
            }
        }

        return [$doomed, $unlink, $kept];
    }

    /**
     * Put back what the load changed on rows that existed before, unless someone changed them since.
     *
     * @param  array<string, array{pk: list<string>}>  $tables
     * @param  array<string, array<string, true>>  $doomed
     */
    private function restore(array $tables, array $doomed): int
    {
        $restored = 0;
        foreach (DB::table('demo_data_changes')->get() as $change) {
            $table = $change->table_name;
            if (! isset($tables[$table]) || count($tables[$table]['pk']) !== 1 || isset($doomed[$table][$change->record_key])) {
                continue;
            }
            $pk = $tables[$table]['pk'][0];
            $before = json_decode((string) $change->before, true) ?: [];
            $current = DB::table($table)->where($pk, $change->record_key)->first();
            if ($change->type === 'deleted') {
                if (! $current && $before !== []) {
                    DB::table($table)->insert($before);
                    $restored++;
                }

                continue;
            }
            if (! $current) {
                continue;
            }
            $after = json_decode((string) $change->after, true) ?: [];
            $put = [];
            foreach ($before as $column => $value) {
                if (property_exists($current, $column) && (string) $current->{$column} === (string) ($after[$column] ?? null)) {
                    $put[$column] = $value;
                }
            }
            if ($put !== []) {
                DB::table($table)->where($pk, $change->record_key)->update($put);
                $restored++;
            }
        }

        return $restored;
    }

    /**
     * Unlink first, then delete children before parents, so it works with foreign keys switched on.
     *
     * @param  array<string, array{pk: list<string>, nullable: array<string, bool>}>  $tables
     * @param  array<string, array<string, true>>  $doomed
     * @param  array<string, array<string, array<string, true>>>  $unlink
     * @return array<string, int>
     */
    private function delete(array $tables, array $doomed, array $unlink): array
    {
        foreach ($unlink as $table => $columns) {
            foreach ($columns as $column => $keys) {
                $this->eachChunk($table, $tables[$table]['pk'], array_keys($keys), fn ($q) => $q->update([$column => null]));
            }
        }

        // Nullable links between doomed rows are cleared too, so only required links decide the order.
        $links = $this->links($tables);
        foreach ($links as [$child, $column, $parent]) {
            if (! empty($doomed[$child]) && ! empty($doomed[$parent]) && ($tables[$child]['nullable'][$column] ?? false)) {
                $this->eachChunk($child, $tables[$child]['pk'], array_keys($doomed[$child]), fn ($q) => $q->update([$column => null]));
            }
        }

        $pending = array_keys(array_filter($doomed));
        $needs = [];   // parent => children that must go first
        foreach ($links as [$child, $column, $parent]) {
            if ($child !== $parent && ! empty($doomed[$child]) && ! empty($doomed[$parent]) && ! ($tables[$child]['nullable'][$column] ?? false)) {
                $needs[$parent][$child] = true;
            }
        }
        $order = [];
        while ($pending !== []) {
            $ready = array_values(array_filter($pending, fn ($t) => array_diff(array_keys($needs[$t] ?? []), $order) === []));
            if ($ready === []) {
                $order = [...$order, ...$pending];   // a cycle of required links: let the database sort it out
                break;
            }
            $order = [...$order, ...$ready];
            $pending = array_values(array_diff($pending, $ready));
        }

        $deleted = [];
        foreach ($order as $table) {
            $keys = array_keys($doomed[$table]);
            $this->eachChunk($table, $tables[$table]['pk'], $keys, fn ($q) => $q->delete());
            $deleted[$table] = count($keys);
        }

        return $deleted;
    }

    /** Invoice numbers continue from the last real invoice, not from the demo's. */
    private function rewindInvoiceNumbers(): void
    {
        if (! Schema::hasTable('invoice_sequences') || ! Schema::hasColumn('invoice_sequences', 'last_number')) {
            return;
        }
        foreach (DB::table('invoice_sequences')->get() as $sequence) {
            // Numbers look like INV-2026-0042 (InvoiceNumbers).
            $numbers = DB::table('invoices')->where('number', 'like', 'INV-'.$sequence->year.'-%')->pluck('number')
                ->map(fn ($n) => (int) substr((string) $n, strlen('INV-'.$sequence->year.'-')))->all();
            DB::table('invoice_sequences')->where('year', $sequence->year)->update(['last_number' => $numbers === [] ? 0 : max($numbers)]);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Schema helpers
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, array{pk: list<string>, incrementing: bool, organization: bool, nullable: array<string, bool>}> */
    private function tables(): array
    {
        $tables = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, self::SKIP, true) && $table !== 'sessions') {
                continue;
            }
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true);
            $tables[$table] = [
                'pk' => $primary['columns'] ?? [],
                'incrementing' => isset($columns['id']) && ($columns['id']['auto_increment'] ?? false),
                'organization' => isset($columns['organization_id']),
                'nullable' => $columns->map(fn ($c) => (bool) $c['nullable'])->all(),
            ];
        }
        // Sessions are only cleaned up (a demo person's), never recorded.
        if (isset($tables['sessions'])) {
            $tables['sessions']['incrementing'] = false;
        }

        return $tables;
    }

    /**
     * Single-column links: [child, column, parent, parent column].
     *
     * @param  array<string, mixed>  $tables
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function links(array $tables): array
    {
        $links = [];
        foreach (array_keys($tables) as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (count($fk['columns']) === 1 && isset($tables[$fk['foreign_table']])) {
                    $links[] = [$table, $fk['columns'][0], $fk['foreign_table'], $fk['foreign_columns'][0]];
                }
            }
        }
        foreach (self::EXTRA_LINKS as $link) {
            if (isset($tables[$link[0]], $tables[$link[2]]) && Schema::hasColumn($link[0], $link[1])) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * Polymorphic links (`x_type` + `x_id`): [table, type column, id column].
     *
     * @param  array<string, array{nullable: array<string, bool>}>  $tables
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function morphs(array $tables): array
    {
        $morphs = [];
        foreach ($tables as $table => $meta) {
            foreach (array_keys($meta['nullable']) as $column) {
                if (str_ends_with($column, '_type') && array_key_exists($id = substr($column, 0, -5).'_id', $meta['nullable'])) {
                    $morphs[] = [$table, $column, $id];
                }
            }
        }

        return $morphs;
    }

    /**
     * The values children use to point at these parent rows, mapped to the parent's key.
     *
     * @param  list<string>  $pk
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function values(string $parent, array $pk, string $column, array $keys): array
    {
        if ($pk === [$column]) {
            return array_combine($keys, $keys);
        }
        $map = [];
        if (count($pk) === 1) {
            foreach (array_chunk($keys, 500) as $chunk) {
                foreach (DB::table($parent)->whereIn($pk[0], $chunk)->get([$pk[0], $column]) as $row) {
                    if ($row->{$column} !== null) {
                        $map[(string) $row->{$column}] = (string) $row->{$pk[0]};
                    }
                }
            }
        }

        return $map;
    }

    /** @param  list<string>  $pk */
    private function key(object $row, array $pk): string
    {
        return count($pk) === 1 ? (string) $row->{$pk[0]} : (string) json_encode(array_map(fn ($c) => (string) $row->{$c}, $pk));
    }

    /**
     * @param  list<string>  $pk
     * @param  list<string>  $keys
     */
    private function eachChunk(string $table, array $pk, array $keys, callable $then): void
    {
        foreach (array_chunk($keys, 500) as $chunk) {
            $query = DB::table($table);
            if (count($pk) === 1) {
                $query->whereIn($pk[0], $chunk);
            } else {
                $query->where(function ($q) use ($chunk, $pk) {
                    foreach ($chunk as $key) {
                        $values = json_decode($key, true);
                        $q->orWhere(fn ($w) => $w->where(array_combine($pk, $values)));
                    }
                });
            }
            $then($query);
        }
    }

    /** @param  array<string, mixed>  $state */
    private function saveState(array $state): void
    {
        unset($state['stuck']);
        PlatformSetting::put(DemoGuard::STATE_SETTING, $state);
    }
}
