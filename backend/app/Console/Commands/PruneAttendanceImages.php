<?php
namespace App\Console\Commands;
use App\Models\AttendanceEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
class PruneAttendanceImages extends Command
{
    protected $signature = 'attendance:prune-images {--dry-run}';
    protected $description = 'Delete private evidence images older than retention; preserve event metadata';
    public function handle(): int
    {
        $cutoff = now('UTC')->subDays((int) config('attendance.image_retention_days'));
        $count = 0;
        AttendanceEvent::whereNotNull('image_path')->where('received_at', '<', $cutoff)
            ->orderBy('id')->chunkById(100, function ($events) use (&$count) {
                foreach ($events as $event) {
                    if (!$this->option('dry-run')) {
                        // Safe re-run if file was already deleted; don't mark absent file as retained.
                        if (Storage::disk('local')->exists($event->image_path) && !Storage::disk('local')->delete($event->image_path)) {
                            $this->warn('Cannot delete event image #'.$event->id); continue;
                        }
                        $event->update(['image_path' => null]);
                    }
                    $count++;
                }
            });
        $this->info(($this->option('dry-run') ? 'Would delete: ' : 'Deleted: ').$count.' images');
        return self::SUCCESS;
    }
}
