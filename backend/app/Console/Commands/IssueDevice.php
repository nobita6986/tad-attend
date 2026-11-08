<?php
namespace App\Console\Commands;
use App\Models\Device;
use Illuminate\Console\Command;
class IssueDevice extends Command
{
    protected $signature = 'devices:issue {name} {--direction=in}';
    protected $description = 'Provision one iPhone and print its secret token once';
    public function handle(): int
    {
        $direction = $this->option('direction');
        if (!in_array($direction, ['in', 'out'], true)) { $this->error('--direction=in|out'); return self::FAILURE; }
        $token = bin2hex(random_bytes(32));
        $device = Device::create(['name' => $this->argument('name'), 'direction' => $direction,
            'token_hash' => hash('sha256', $token), 'active' => true]);
        $this->info('Device ID: '.$device->id);
        $this->warn('CHỈ HIỂN THỊ 1 LẦN — lưu token vào Keychain của iPhone:');
        $this->line($token);
        return self::SUCCESS;
    }
}
