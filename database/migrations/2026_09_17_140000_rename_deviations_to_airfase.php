<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameReport('deviations', 'airfase', 'отклонений', 'AirFASE');
    }

    public function down(): void
    {
        $this->renameReport('airfase', 'deviations', 'AirFASE', 'отклонений');
    }

    private function renameReport(string $from, string $to, string $oldLabel, string $newLabel): void
    {
        // Check collisions before MySQL DDL, which cannot be rolled back with the data transaction.
        foreach (['reader' => 'Просмотр', 'importer' => 'Импорт'] as $suffix => $label) {
            $role = DB::table('roles')->where('key', "$from-$suffix")->first();
            if (! $role) continue;
            if (DB::table('roles')->where('key', "$to-$suffix")->where('id', '!=', $role->id)->exists()
                || ($role->name === "$label $oldLabel" && DB::table('roles')->where('name', "$label $newLabel")->where('id', '!=', $role->id)->exists())) {
                throw new RuntimeException("Cannot rename $from: conflicting role $to-$suffix.");
            }
        }
        if (Schema::hasTable($from)) {
            if (Schema::hasTable($to)) throw new RuntimeException("Both $from and $to tables exist; no data was overwritten.");
            Schema::rename($from, $to);
        } elseif (! Schema::hasTable($to)) {
            throw new RuntimeException("Neither $from nor $to table exists.");
        }

        // Keep constraint names consistent too; each step tolerates a retry after partial MySQL DDL.
        foreach (Schema::getIndexes($to) as $index) {
            if (str_starts_with($index['name'], $from.'_')) {
                Schema::table($to, fn (Blueprint $table) => $table->renameIndex($index['name'], $to.substr($index['name'], strlen($from))));
            }
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (Schema::getForeignKeys($to) as $foreign) {
                if ($foreign['name'] === $from.'_uploaded_by_foreign') {
                    Schema::table($to, fn (Blueprint $table) => $table->dropForeign($from.'_uploaded_by_foreign'));
                }
            }
            if (! collect(Schema::getForeignKeys($to))->contains(fn ($foreign) => $foreign['columns'] === ['uploaded_by'])) {
                Schema::table($to, fn (Blueprint $table) => $table->foreign('uploaded_by', $to.'_uploaded_by_foreign')->references('id')->on('users')->nullOnDelete());
            }
        }

        DB::transaction(function () use ($from, $to, $oldLabel, $newLabel) {
            foreach (['roles', 'users'] as $table) {
                DB::table($table)->whereNotNull('permissions')->orderBy('id')->chunkById(200, function ($records) use ($table, $from, $to) {
                    $mapping = ["$from.view" => "$to.view", "$from.read" => "$to.read", "$from.import" => "$to.import"];
                    foreach ($records as $record) {
                        $old = json_decode($record->permissions, true, 512, JSON_THROW_ON_ERROR);
                        $new = array_values(array_unique(array_map(fn ($permission) => $mapping[$permission] ?? $permission, $old)));
                        if ($new !== $old) DB::table($table)->where('id', $record->id)->update(['permissions' => json_encode($new, JSON_THROW_ON_ERROR)]);
                    }
                });
            }
            foreach (['reader' => 'Просмотр', 'importer' => 'Импорт'] as $suffix => $label) {
                $role = DB::table('roles')->where('key', "$from-$suffix")->first();
                if (! $role) continue;
                DB::table('roles')->where('id', $role->id)->update([
                    'key' => "$to-$suffix",
                    // Preserve administrator-customized role names.
                    'name' => $role->name === "$label $oldLabel" ? "$label $newLabel" : $role->name,
                ]);
            }
        });
        foreach ([$from, $to] as $table) Cache::forget('report-filter-options:v1:'.$table);
    }
};
