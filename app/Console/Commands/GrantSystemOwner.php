<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('organizations:system-owner
    {email : The email address of the user}
    {--revoke : Revoke system owner access instead of granting it}'
)]
#[Description('Grant or revoke the system-owner permission (the ability to create organizations)')]
class GrantSystemOwner extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user found with email \"{$this->argument('email')}\".");

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $user->is_system_owner = false;
            $user->save();

            $this->info("Revoked system owner access from {$user->email}.");

            return self::SUCCESS;
        }

        if (config('organizations.hosting_mode') === 'hosted') {
            $existing = User::where('is_system_owner', true)
                ->where('id', '!=', $user->id)
                ->first();

            if ($existing) {
                $this->error("Hosting mode is \"hosted\", which allows only one system owner. {$existing->email} already holds this permission — revoke it first with --revoke.");

                return self::FAILURE;
            }
        }

        $user->is_system_owner = true;
        $user->save();

        $this->info("Granted system owner access to {$user->email}.");

        return self::SUCCESS;
    }
}
