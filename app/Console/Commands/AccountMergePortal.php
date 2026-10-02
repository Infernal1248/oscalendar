<?php

namespace App\Console\Commands;

use App\Models\{ParserTask, PortalCredential, Role, User};
use App\Services\ParserTaskScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Crypt, DB, Schema};
use RuntimeException;

class AccountMergePortal extends Command
{
    protected $signature = 'account:merge-portal {source : Local login of the test account} {target : Local login of the administrator} {--target-id= : Expected administrator ID} {--apply : Apply the transfer and delete the source account}';
    protected $description = 'Preview or transfer a test account portal data to an administrator, preserving administrator authentication';

    private const PORTAL_TABLES = ['roster_items', 'flight_segments', 'flight_documents', 'roster_change_events', 'portal_profiles'];

    public function handle(): int
    {
        try {
            DB::transaction(function () {
                Role::lockAdministration();
                $sourceLogin = (string) $this->argument('source');
                $targetLogin = (string) $this->argument('target');
                $users = User::whereIn('login', [$sourceLogin, $targetLogin])->orderBy('id')->lockForUpdate()->get();
                $from = $users->where('login', $sourceLogin)->sole();
                $to = $users->where('login', $targetLogin)->sole();
                $this->require($from->id !== $to->id && ! $from->isAdmin() && $to->isAdmin(), 'Expected a non-admin source and a different administrator target.');
                $this->require($to->status === 'active', 'The target administrator is not active.');
                $this->require($this->option('target-id') !== null && (string) $to->id === (string) $this->option('target-id'), 'Supply --target-id matching the administrator ID.');

                $ids = [$from->id, $to->id];
                $tasks = ParserTask::whereIn('user_id', $ids)->orderBy('id')->lockForUpdate()->get();
                $this->require(! $tasks->contains('status', 'running'), 'Parser tasks are running. Stop both parser supervisors, wait for workers to finish, then retry.');
                $this->require(! DB::table('sync_runs')->whereIn('user_id', $ids)->whereIn('status', ['queued', 'running'])->exists(), 'Unfinished sync runs exist. Finish/reconcile them before merging.');
                $credentials = PortalCredential::whereIn('user_id', $ids)->orderBy('id')->lockForUpdate()->get();
                $sourceCredentials = $credentials->where('user_id', $from->id);
                $this->require($sourceCredentials->count() === 1, 'Expected exactly one portal credential on the source account.');
                $credential = $sourceCredentials->first();
                $this->require($credential->portal === 'rossiya_edu' && trim($credential->login) !== '', 'Expected an OpenSky credential with a non-empty login.');
                // Verify encryption without printing the login, password or ciphertext.
                $this->require(Crypt::decryptString($credential->password_encrypted) !== '', 'Stored portal password is empty.');
                $this->require($credentials->where('user_id', $to->id)->every(fn ($row) => $row->portal === $credential->portal), 'Target has a different portal binding.');
                $this->require($tasks->every(fn ($task) => $task->source === 'rossiya_edu' && $task->portal === 'rossiya_edu'), 'Unexpected portal tasks exist.');
                $this->require($tasks->where('user_id', $to->id)->every(fn ($task) => $task->task_type === 'roster_refresh' && $task->source === 'rossiya_edu'), 'Target has non-roster parser tasks.');
                foreach (self::PORTAL_TABLES as $table) {
                    if (Schema::hasTable($table)) {
                        $this->require(! DB::table($table)->where('user_id', $to->id)->exists(), "Target already contains {$table}; refusing to overwrite it.");
                    }
                }

                // These identities could grant access to the administrator or silently lose
                // financial/report records. Require separate handling instead of merging them.
                foreach (['telegram_accounts' => ['user_id'], 'subscription_payments' => ['user_id', 'recorded_by', 'canceled_by'],
                    'payment_orders' => ['user_id'], 'payment_notifications' => ['user_id'],
                    'airfase' => ['uploaded_by', 'demo_user_id'], 'green_zone_flights' => ['uploaded_by', 'demo_user_id'],
                    'rrj_express_events' => ['uploaded_by', 'demo_user_id']] as $table => $columns) {
                    if (! Schema::hasTable($table)) continue;
                    foreach ($columns as $column) {
                        if (Schema::hasColumn($table, $column)) {
                            $this->require(! DB::table($table)->where($column, $from->id)->exists(), "Source has records in {$table}.{$column}; handle these separately before deleting the account.");
                        }
                    }
                }

                $this->info("Source: #{$from->id} {$from->login}; target: #{$to->id} {$to->login}");
                $counts = [];
                foreach (array_merge(self::PORTAL_TABLES, ['parser_tasks', 'sync_runs']) as $table) {
                    if (Schema::hasTable($table)) $counts[] = [$table, DB::table($table)->where('user_id', $from->id)->count()];
                }
                $this->table(['Records to transfer', 'Count'], $counts);
                $this->line('Administrator login, password, roles and existing sessions remain unchanged.');
                $this->line('Source web tokens, calendar links and push subscriptions will be revoked, not inherited.');
                $this->line('Source account will be deleted. Target roster task will be scheduled immediately.');
                if (! $this->option('apply')) {
                    $this->info('Preview only: no changes made. Back up the database and stop parser supervisors before --apply.');
                    return;
                }
                // Parent rows retain their IDs, keeping child segments, crew, documents,
                // deferred items and sync logs connected without copying or losing records.
                PortalCredential::where('user_id', $to->id)->where('portal', $credential->portal)->delete();
                $credential->forceFill(['user_id' => $to->id, 'status' => 'active',
                    'last_error_at' => null, 'last_error_text' => null])->save();
                foreach (self::PORTAL_TABLES as $table) {
                    if (Schema::hasTable($table)) DB::table($table)->where('user_id', $from->id)->update(['user_id' => $to->id]);
                }
                DB::table('sync_runs')->where('user_id', $from->id)->update(['user_id' => $to->id]);
                foreach ($tasks->where('user_id', $from->id) as $task) {
                    if ($task->task_type === 'roster_refresh') {
                        $existing = $tasks->first(fn ($other) => $other->user_id === $to->id && $other->task_type === 'roster_refresh' && $other->source === $task->source);
                        if ($existing) {
                            DB::table('sync_runs')->where('parser_task_id', $task->id)->update(['parser_task_id' => $existing->id]);
                            $task->delete();
                            continue;
                        }
                        $task->task_key = "roster_refresh:{$task->source}:user:{$to->id}";
                    }
                    $task->user_id = $to->id;
                    $task->save();
                }
                // Old message IDs belong to the source chat, not the administrator's chat.
                DB::table('roster_change_events')->where('user_id', $to->id)->update([
                    'telegram_messages' => null, 'acknowledgement_messages' => null,
                ]);
                $from->tokens()->delete();
                DB::table('calendar_feeds')->where('user_id', $from->id)->delete();
                if (Schema::hasTable('push_subscriptions')) DB::table('push_subscriptions')->where('user_id', $from->id)->delete();
                if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) DB::table('sessions')->where('user_id', $from->id)->delete();
                $from->delete();
                $scheduler = app(ParserTaskScheduler::class);
                $scheduler->ensureRosterTasks('rossiya_edu', 'rossiya_edu');
                ParserTask::where('user_id', $to->id)->where('task_type', 'roster_refresh')->where('source', 'rossiya_edu')->update([
                    'status' => 'scheduled', 'next_run_at' => now(), 'last_error_at' => null, 'last_error_text' => null,
                    'locked_at' => null, 'lock_expires_at' => null, 'locked_by' => null, 'refresh_requested' => false,
                ]);
                $this->info('Transfer complete. Restart the parser supervisors and check the next successful roster refresh.');
            });
        } catch (\Throwable $e) {
            // Never print SQL/exception details: they could contain encrypted credentials.
            $this->error($e instanceof RuntimeException && get_class($e) === RuntimeException::class
                ? $e->getMessage() : 'Transfer aborted and rolled back. Check account logins and schema; no credentials were printed.');
            return self::FAILURE;
        }
        return self::SUCCESS;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) throw new RuntimeException($message);
    }
}
