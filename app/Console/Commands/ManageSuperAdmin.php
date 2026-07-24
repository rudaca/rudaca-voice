<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:make-super-admin {email : The email of the user} {--revoke : Revoke Super Admin access instead of granting it}')]
#[Description('Grant or revoke Super Admin access for a user')]
class ManageSuperAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user found with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $isSuperAdmin = ! $this->option('revoke');

        $user->is_super_admin = $isSuperAdmin;
        $user->save();

        $this->info(
            $isSuperAdmin
                ? "{$user->name} ({$user->email}) is now a Super Admin."
                : "{$user->name} ({$user->email}) is no longer a Super Admin."
        );

        return self::SUCCESS;
    }
}
