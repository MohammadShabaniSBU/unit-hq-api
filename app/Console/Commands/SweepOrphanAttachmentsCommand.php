<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MessageAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SweepOrphanAttachmentsCommand extends Command
{
    protected $signature = 'comms:sweep-orphan-attachments';

    protected $description = 'Delete staged message attachments (message_id null) older than the staging TTL';

    public function handle(): int
    {
        $hours = (int) config('communications.staging.orphan_ttl_hours', 24);
        $cutoff = now()->subHours($hours);

        $orphans = MessageAttachment::query()
            ->whereNull('message_id')
            ->where('created_at', '<', $cutoff)
            ->get();

        $disk = Storage::disk('local');
        $deleted = 0;

        foreach ($orphans as $orphan) {
            if (is_string($orphan->disk_path) && $orphan->disk_path !== '' && $disk->exists($orphan->disk_path)) {
                $disk->delete($orphan->disk_path);
                $dir = dirname($orphan->disk_path);
                if ($dir !== '.' && $dir !== '' && empty($disk->files($dir))) {
                    $disk->deleteDirectory($dir);
                }
            }

            $orphan->delete();
            $deleted++;
        }

        $this->info("Swept {$deleted} orphan attachment(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
