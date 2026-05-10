<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Removes attachment / image rows whose underlying file no longer exists on
 * the public disk.
 *
 * Why this exists: in production we have observed `unit_images` rows whose
 * file path was recorded in the DB but the file itself is missing from
 * `storage/app/public/...` (most likely lost during a botched server-side
 * cleanup of the `public_html/storage` symlink). The UI then renders broken
 * thumbnails forever because the row keeps the URL alive.
 *
 * The command is idempotent and safe to run on every deploy. It only
 * touches tables that exist and only deletes rows whose `path` column
 * resolves to a missing file on the configured public disk.
 *
 * Usage:
 *   php artisan attachments:purge-orphans              # dry-run (default)
 *   php artisan attachments:purge-orphans --apply
 */
class PurgeOrphanAttachments extends Command
{
    protected $signature = 'attachments:purge-orphans
                            {--apply : Actually delete orphan rows (default is dry-run)}';

    protected $description = 'Delete attachment rows whose stored file no longer exists on disk.';

    /**
     * @var array<string, array{column:string}>
     */
    private array $map = [
        'unit_images' => ['column' => 'path'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $totalOrphan = 0;
        $totalRemoved = 0;

        foreach ($this->map as $table => $cfg) {
            if (!Schema::hasTable($table)) {
                $this->warn("→ {$table}: skipped (table missing)");
                continue;
            }
            $col = $cfg['column'];
            if (!Schema::hasColumn($table, $col)) {
                $this->warn("→ {$table}: skipped ({$col} column missing)");
                continue;
            }

            $this->line("→ <comment>{$table}</comment>");
            [$found, $removed] = $this->processTable($table, $col, $apply);
            $verb = $apply ? 'removed' : 'would-remove';
            $this->line("    orphans={$found}  {$verb}={$removed}");
            $totalOrphan  += $found;
            $totalRemoved += $removed;
        }

        $this->info("Total orphans: {$totalOrphan}  |  " . ($apply ? "removed" : "would-remove") . ": {$totalRemoved}");

        if (!$apply && $totalOrphan > 0) {
            $this->warn('Re-run with --apply to actually delete the orphan rows.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{int,int} [found, removed]
     */
    private function processTable(string $table, string $col, bool $apply): array
    {
        $found = 0;
        $removed = 0;

        $disk = Storage::disk('public');

        DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table, $col, $apply, $disk, &$found, &$removed) {
            foreach ($rows as $row) {
                $path = $row->{$col} ?? null;
                if (!is_string($path) || $path === '') {
                    continue;
                }
                if ($disk->exists($path)) {
                    continue;
                }
                $found++;
                if ($apply) {
                    DB::table($table)->where('id', $row->id)->delete();
                    $removed++;
                } else {
                    $removed++;
                }
            }
        }, 'id');

        return [$found, $removed];
    }
}
