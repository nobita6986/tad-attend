<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email}';
    protected $description = 'Create a web admin (interactive password prompt)';
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = $this->ask('Tên người quản trị');
        $password = $this->secret('Mật khẩu >= 12 ký tự');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string) $password) < 12) {
            $this->error('Email không hợp lệ hoặc mật khẩu dưới 12 ký tự.'); return self::FAILURE;
        }
        User::updateOrCreate(['email' => $email], ['name' => $name ?: 'Administrator', 'password' => Hash::make($password), 'is_admin' => true]);
        $this->info('Đã tạo/cập nhật admin.'); return self::SUCCESS;
    }
}
