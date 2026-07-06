<?php

namespace Database\Seeders;

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaBoard;
use App\Models\IdeaBoardGroup;
use App\Models\IdeaBoardRoleAccess;
use App\Models\IdeaBoardUserAccess;
use App\Models\IdeaCategory;
use App\Models\IdeaComment;
use App\Models\IdeaGithubLink;
use App\Models\IdeaStatusHistory;
use App\Models\IdeaVote;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IdeaPortalSeeder extends Seeder
{
    /**
     * Seed a realistic employee ideas / business-improvement portal for a sample team.
     */
    public function run(): void
    {
        $team = Team::factory()->create([
            'name' => 'Rudaca Travel',
            'slug' => 'rudaca-travel',
            'is_personal' => false,
        ]);

        // --- Users & memberships (starter-kit team_members structure) ---
        $owner = $this->addMember($team, 'Olivia Owner', 'owner@rudaca.test', TeamRole::Owner);
        $admin = $this->addMember($team, 'Amir Admin', 'admin@rudaca.test', TeamRole::Admin);
        $manager = $this->addMember($team, 'Maria Manager', 'manager@rudaca.test', TeamRole::Manager);
        $viewer = $this->addMember($team, 'Vince Viewer', 'viewer@rudaca.test', TeamRole::Viewer);

        $employees = collect([
            'Test User' => 'test@example.com',
            'Ella Employee' => 'ella@rudaca.test',
            'Ben Employee' => 'ben@rudaca.test',
            'Carla Employee' => 'carla@rudaca.test',
        ])->map(fn (string $email, string $name) => $this->addMember($team, $name, $email, TeamRole::Employee))
            ->values();

        $contributors = $employees->push($manager);            // people who submit ideas
        $reviewers = collect([$owner, $admin, $manager]);       // people who review / change status
        $allUsers = $employees->merge([$owner, $admin, $viewer])->unique('id')->values();

        // --- Board groups ---
        $operationsGroup = IdeaBoardGroup::factory()->create([
            'team_id' => $team->id,
            'name' => 'Company Operations',
            'slug' => 'company-operations',
            'description' => 'Ideas for improving day-to-day operations, processes, and finance workflows.',
            'sort_order' => 1,
            'created_by_user_id' => $owner->id,
        ]);

        $productGroup = IdeaBoardGroup::factory()->create([
            'team_id' => $team->id,
            'name' => 'Product & Technology',
            'slug' => 'product-technology',
            'description' => 'Ideas for our internal tools, website, and software.',
            'sort_order' => 2,
            'created_by_user_id' => $admin->id,
        ]);

        // --- Boards ---
        $boards = collect([
            'operations' => ['Operations', $operationsGroup, 'Operational and process improvement ideas.'],
            'accounting' => ['Accounting', $operationsGroup, 'Finance, billing, and reporting ideas.'],
            'technology' => ['Technology', $productGroup, 'Internal tools and automation ideas.'],
            'website' => ['Website', $productGroup, 'Public website and customer-facing ideas.'],
        ])->map(function (array $data) use ($team, $owner) {
            [$name, $group, $description] = $data;

            return IdeaBoard::factory()->create([
                'team_id' => $team->id,
                'board_group_id' => $group->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $description,
                'visibility' => 'internal',
                'sort_order' => 1,
                'created_by_user_id' => $owner->id,
            ]);
        });

        // --- Categories (several, scoped per board) ---
        $categoryMap = [
            'operations' => ['Process Improvement', 'Customer Service', 'Reporting'],
            'accounting' => ['Automation', 'Reporting', 'Cost Savings'],
            'technology' => ['Software Request', 'Automation'],
            'website' => ['Software Request', 'Customer Service'],
        ];

        $categories = [];
        foreach ($categoryMap as $boardKey => $names) {
            foreach (array_values($names) as $i => $name) {
                $slug = Str::slug($name);
                $categories[$boardKey][$slug] = IdeaCategory::factory()->create([
                    'team_id' => $team->id,
                    'board_id' => $boards[$boardKey]->id,
                    'name' => $name,
                    'slug' => $slug,
                    'sort_order' => $i + 1,
                ]);
            }
        }

        // --- Ideas ---
        $ideasData = $this->ideasData();

        /** @var Collection<int, Idea> $ideas */
        $ideas = new Collection;

        foreach ($ideasData as $data) {
            $board = $boards[$data['board']];

            $ideas->push(
                Idea::factory()
                    ->when($data['anonymous'] ?? false, fn ($factory) => $factory->anonymous())
                    ->when($data['private'] ?? false, fn ($factory) => $factory->private())
                    ->create([
                        'team_id' => $team->id,
                        'board_group_id' => $board->board_group_id,
                        'board_id' => $board->id,
                        'category_id' => $categories[$data['board']][$data['category']]->id,
                        'submitted_by_user_id' => $contributors->random()->id,
                        'title' => $data['title'],
                        'slug' => Str::slug($data['title']),
                        'description' => $data['description'],
                        'status' => $data['status'],
                        'priority' => $data['priority'],
                        'impact' => $data['impact'],
                        'effort' => $data['effort'],
                    ])
            );
        }

        // Wire up the duplicate relationship (idea marked "duplicate" points at its original).
        foreach ($ideasData as $index => $data) {
            if (isset($data['duplicate_of'])) {
                $ideas[$index]->update([
                    'duplicate_of_idea_id' => $ideas[$data['duplicate_of']]->id,
                ]);
            }
        }

        // --- Votes: a random distinct subset of users per idea ---
        foreach ($ideas as $idea) {
            $voterCount = fake()->numberBetween(0, $allUsers->count());

            $allUsers->shuffle()->take($voterCount)->each(function (User $voter) use ($idea) {
                IdeaVote::factory()->create([
                    'idea_id' => $idea->id,
                    'user_id' => $voter->id,
                ]);
            });
        }

        // --- Comments: public discussion on most ideas, plus some internal notes ---
        foreach ($ideas as $idea) {
            IdeaComment::factory()
                ->count(fake()->numberBetween(0, 3))
                ->create([
                    'idea_id' => $idea->id,
                    'user_id' => $allUsers->random()->id,
                ]);

            if (fake()->boolean(35)) {
                IdeaComment::factory()->internal()->create([
                    'idea_id' => $idea->id,
                    'user_id' => $reviewers->random()->id,
                ]);
            }
        }

        // --- Status history: any idea that has moved past "new" gets a history entry ---
        $ideas->reject(fn (Idea $idea) => $idea->status === 'new')
            ->each(function (Idea $idea) use ($reviewers) {
                IdeaStatusHistory::factory()->create([
                    'idea_id' => $idea->id,
                    'changed_by_user_id' => $reviewers->random()->id,
                    'old_status' => 'new',
                    'new_status' => $idea->status,
                ]);
            });

        // --- Optional sample GitHub links (schema only, no sync logic) ---
        $ideas->whereIn('status', ['planned', 'in_progress'])
            ->take(3)
            ->each(function (Idea $idea) {
                IdeaGithubLink::factory()->create([
                    'idea_id' => $idea->id,
                    'github_issue_status' => $idea->status === 'in_progress' ? 'in_progress' : 'ready',
                ]);
            });

        // --- Optional Phase 2 board-access sample rows (not enforced anywhere yet) ---
        IdeaBoardRoleAccess::factory()->create([
            'board_id' => $boards['website']->id,
            'role' => TeamRole::Viewer,
        ]);

        IdeaBoardUserAccess::factory()->create([
            'board_id' => $boards['technology']->id,
            'user_id' => $employees->first()->id,
            'access_level' => 'contribute',
        ]);

        $this->command?->info("Seeded '{$team->name}' with {$ideas->count()} ideas across {$boards->count()} boards.");
        $this->command?->info('Sample owner login: owner@rudaca.test / password');
    }

    /**
     * Create a user and attach them to the team with the given role.
     */
    private function addMember(Team $team, string $name, string $email, TeamRole $role): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);

        $team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($team);

        return $user;
    }

    /**
     * Realistic sample ideas for a travel business improvement portal.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ideasData(): array
    {
        return [
            [
                'title' => 'Automate weekly commission reconciliation',
                'board' => 'accounting',
                'category' => 'automation',
                'status' => 'in_progress',
                'priority' => 'high',
                'impact' => 'high',
                'effort' => 'medium',
                'description' => 'Finance spends most of Monday matching supplier commission statements to bookings by hand. An automated match-and-flag report would save hours and reduce errors.',
            ],
            [
                'title' => 'Self-service booking change portal for tour leaders',
                'board' => 'technology',
                'category' => 'software-request',
                'status' => 'planned',
                'priority' => 'high',
                'impact' => 'high',
                'effort' => 'large',
                'description' => 'Tour leaders currently email the office for every itinerary change. A simple portal would let them request approved changes directly and cut back-and-forth.',
            ],
            [
                'title' => 'Reduce duplicate data entry between Atlas and accounting',
                'board' => 'operations',
                'category' => 'process-improvement',
                'status' => 'under_review',
                'priority' => 'high',
                'impact' => 'medium',
                'effort' => 'medium',
                'description' => 'The same booking details are keyed into Atlas and then again into the accounting system. A one-way sync would remove double entry and mismatches.',
            ],
            [
                'title' => 'Improve the new employee onboarding checklist',
                'board' => 'operations',
                'category' => 'process-improvement',
                'status' => 'new',
                'priority' => 'medium',
                'impact' => 'medium',
                'effort' => 'small',
                'description' => 'New starters often miss access to key systems in week one. A shared onboarding checklist with owners for each step would smooth this out.',
            ],
            [
                'title' => 'Faster passport and visa document collection',
                'board' => 'operations',
                'category' => 'customer-service',
                'status' => 'under_review',
                'priority' => 'medium',
                'impact' => 'medium',
                'effort' => 'medium',
                'description' => 'Chasing travellers for passport and visa documents is manual and slow. Automated reminders with secure upload links would speed this up.',
            ],
            [
                'title' => 'Show multi-currency pricing on the website',
                'board' => 'website',
                'category' => 'software-request',
                'status' => 'planned',
                'priority' => 'medium',
                'impact' => 'high',
                'effort' => 'large',
                'description' => 'International visitors want to see prices in their own currency. Displaying converted prices could improve conversion on the public site.',
            ],
            [
                'title' => 'Standardize supplier contract templates',
                'board' => 'operations',
                'category' => 'process-improvement',
                'status' => 'new',
                'priority' => 'low',
                'impact' => 'medium',
                'effort' => 'small',
                'description' => 'Every supplier contract is written from scratch. A set of approved templates would save time and keep terms consistent.',
            ],
            [
                'title' => 'Automated reminders for outstanding client payments',
                'board' => 'accounting',
                'category' => 'automation',
                'status' => 'in_progress',
                'priority' => 'high',
                'impact' => 'high',
                'effort' => 'medium',
                'description' => 'Overdue balances are tracked in a spreadsheet and chased manually. Scheduled reminder emails at set intervals would improve cash flow.',
            ],
            [
                'title' => 'Central dashboard for trip profitability',
                'board' => 'accounting',
                'category' => 'reporting',
                'status' => 'under_review',
                'priority' => 'high',
                'impact' => 'high',
                'effort' => 'large',
                'private' => true,
                'description' => 'Managers cannot easily see margin per trip. A profitability dashboard would help prioritise the most valuable products.',
            ],
            [
                'title' => 'Improve communication between sales and operations',
                'board' => 'operations',
                'category' => 'process-improvement',
                'status' => 'new',
                'priority' => 'medium',
                'impact' => 'medium',
                'effort' => 'small',
                'description' => 'Details agreed by sales are not always passed cleanly to operations. A short handover template per booking would reduce dropped details.',
            ],
            [
                'title' => 'Self-service knowledge base for common client questions',
                'board' => 'website',
                'category' => 'customer-service',
                'status' => 'planned',
                'priority' => 'medium',
                'impact' => 'medium',
                'effort' => 'medium',
                'description' => 'A searchable FAQ / knowledge base would deflect repetitive client questions and free up the reservations team.',
            ],
            [
                'title' => 'Bulk itinerary PDF generation',
                'board' => 'technology',
                'category' => 'automation',
                'status' => 'released',
                'priority' => 'medium',
                'impact' => 'medium',
                'effort' => 'medium',
                'description' => 'Generating itinerary PDFs one at a time is tedious for large group departures. Bulk generation would save the operations team time.',
            ],
            [
                'title' => 'Track and reduce refund processing time',
                'board' => 'operations',
                'category' => 'reporting',
                'status' => 'under_review',
                'priority' => 'high',
                'impact' => 'medium',
                'effort' => 'medium',
                'description' => 'We have no visibility on how long refunds take. Tracking the time from request to payout would help us set and hit a service target.',
            ],
            [
                'title' => 'Mobile-friendly expense submission for tour leaders',
                'board' => 'technology',
                'category' => 'software-request',
                'status' => 'not_doing',
                'priority' => 'low',
                'impact' => 'low',
                'effort' => 'medium',
                'description' => 'Tour leaders want to submit expenses from their phones on the road. Reviewed but deferred in favour of higher-impact tooling this year.',
            ],
            [
                'title' => 'Automate commission report generation',
                'board' => 'accounting',
                'category' => 'automation',
                'status' => 'duplicate',
                'priority' => 'low',
                'impact' => 'medium',
                'effort' => 'small',
                'anonymous' => true,
                'duplicate_of' => 0,
                'description' => 'Generate the weekly commission report automatically instead of building it by hand. Covered by the existing commission reconciliation idea.',
            ],
        ];
    }
}
