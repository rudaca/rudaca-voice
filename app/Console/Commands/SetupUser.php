<?php

namespace App\Console\Commands;

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('user:setup
    {--email= : The email address of the user}
    {--name= : The name of the user}
    {--password= : The password for the user}
    {--owner : Assign the Owner role (creates a new team if --team_id is not given)}
    {--role= : The role to assign (admin, manager, employee, viewer)}
    {--team_id= : The team to attach the user to (required unless --owner is used without one)}'
)]
#[Description('Create a user account and assign them a team role')]
class SetupUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $role = $this->resolveRole();

        if (! $role) {
            return self::FAILURE;
        }

        $team = $this->resolveTeam($role);

        if ($team === false) {
            return self::FAILURE;
        }

        $validator = Validator::make([
            'email' => $this->option('email'),
            'name' => $this->option('name'),
            'password' => $this->option('password'),
        ], [
            'email' => ['required', 'email', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::default()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();

        $user = DB::transaction(function () use ($data, $role, $team) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'email_verified_at' => now(),
            ]);

            if ($role === TeamRole::Owner && ! $team) {
                app(CreateTeam::class)->handle($user, name: "{$user->name}'s Team", isPersonal: true);
            } else {
                $team->memberships()->create([
                    'user_id' => $user->id,
                    'role' => $role,
                ]);

                $user->switchTeam($team);
            }

            return $user;
        });

        $this->info("User {$user->name} ({$user->email}) created as {$role->label()} of {$user->currentTeam->name}.");

        return self::SUCCESS;
    }

    /**
     * Resolve and validate the role to assign, printing an error and
     * returning null if the options given are invalid or ambiguous.
     */
    private function resolveRole(): ?TeamRole
    {
        $owner = $this->option('owner');
        $role = $this->option('role');

        if ($owner && $role) {
            $this->error('Use either --owner or --role, not both.');

            return null;
        }

        if ($owner) {
            return TeamRole::Owner;
        }

        if (! $role) {
            $this->error('You must specify either --owner or --role=<role>.');

            return null;
        }

        $assignable = collect(TeamRole::assignable())->pluck('value');

        if (! $assignable->contains($role)) {
            $this->error("Invalid role \"{$role}\". Valid roles are: {$assignable->implode(', ')}.");

            return null;
        }

        return TeamRole::from($role);
    }

    /**
     * Resolve the team for the given role, printing an error and returning
     * false if the team is missing or invalid for the role.
     *
     * @return Team|null|false Null means a new team should be created for an owner.
     */
    private function resolveTeam(TeamRole $role): Team|null|false
    {
        $teamId = $this->option('team_id');

        if (! $teamId) {
            if ($role === TeamRole::Owner) {
                return null;
            }

            $this->error('--team_id is required unless --owner is used to create a new team.');

            return false;
        }

        $team = Team::find($teamId);

        if (! $team) {
            $this->error("No team found with id {$teamId}.");

            return false;
        }

        if ($role === TeamRole::Owner && $team->owner()) {
            $this->error("Team \"{$team->name}\" already has an owner.");

            return false;
        }

        return $team;
    }
}
