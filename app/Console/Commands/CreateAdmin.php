<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Create an admin account from the command line.
 *
 * Sign-up only ever creates customers and the demo seeder refuses to run in
 * production, so this is how a new installation gets its first admin. That
 * admin then creates the branches and the other staff from the admin pages.
 */
class CreateAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kotak:create-admin';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an admin account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = text(
            label: 'Name',
            required: true,
            validate: ['name' => 'max:255'],
        );

        $email = Str::lower(text(
            label: 'Email',
            required: true,
            validate: ['email' => 'email|max:255|unique:users,email'],
        ));

        $password = password(
            label: 'Password',
            required: true,
            validate: ['password' => [Password::defaults()]],
        );

        $user = new User;

        // Role, active flag and verification are never mass assignable.
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => Role::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        $this->components->info("Admin account created for {$user->email}.");

        return self::SUCCESS;
    }
}
