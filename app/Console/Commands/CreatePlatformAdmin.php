<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Bootstraps the very first super admin account on a fresh deploy. There's no other way to get
 * one — the /platform/* routes that create stations are themselves gated to super admins, and
 * "super admin" just means "a user that exists in the central users table" (see
 * HandleInertiaRequests::share()), so this is a one-time chicken-and-egg fix run once by hand.
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email} {--name=Owner}';

    protected $description = 'Create the platform (super admin) account used to sign up new stations';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That does not look like a valid email address.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email {$email} already exists.");

            return self::FAILURE;
        }

        $password = Str::password(16);

        User::create([
            'name' => $this->option('name'),
            'email' => $email,
            'password' => $password,
        ]);

        $this->info('Platform admin created.');
        $this->line("Email:    {$email}");
        $this->line("Password: {$password}");
        $this->warn('Save this password now — it will not be shown again.');

        return self::SUCCESS;
    }
}
