<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreatePanelUser extends Command
{
    protected $signature = 'panel:user
        {email : User email}
        {--name= : User display name}
        {--password= : User password. Generated when omitted}
        {--role=admin : User role}
        {--token-name=panel : Sanctum token name}';

    protected $description = 'Create or update a panel user and issue a Sanctum API token.';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $password = (string) ($this->option('password') ?: Str::password(18));
        $role = (string) $this->option('role');

        if (!in_array($role, ['operator', 'admin', 'developer'], true)) {
            $this->error('Role must be operator, admin, or developer.');
            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $this->option('name') ?: Str::before($email, '@'),
                'password' => Hash::make($password),
                'role' => $role,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $token = $user->createToken((string) $this->option('token-name'), ['recordings:manage'])->plainTextToken;

        $this->info('Panel user ready.');
        $this->line('Email: ' . $user->email);
        $this->line('Role: ' . $user->role);
        $this->line('Password: ' . $password);
        $this->line('API token: ' . $token);

        return self::SUCCESS;
    }
}
