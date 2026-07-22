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
            'name' => 'Ellison Travel',
            'slug' => 'ellison-travel',
            'is_personal' => false,
        ]);

        // --- Users & memberships (starter-kit team_members structure) ---
        $owner = $this->addMember($team, 'Aaron Asuncion', 'aarona@ellisontravel.com', TeamRole::Owner);
        $admin = $this->addMember($team, 'Priya Shah', 'admin@ellisontravel.com', TeamRole::Admin);
        $manager = $this->addMember($team, 'Marcus Bennett', 'manager@ellisontravel.com', TeamRole::Manager);
        $viewer = $this->addMember($team, 'Grace Kim', 'viewer@ellisontravel.com', TeamRole::Viewer);

        $employees = collect([
            'Sofia Ramirez' => 'sofia@ellisontravel.com',
            'Jake Thompson' => 'jake@ellisontravel.com',
            'Priya Patel' => 'priya.patel@ellisontravel.com',
            'Noah Fischer' => 'noah@ellisontravel.com',
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

        // --- Ideas: 20 curated templates (2 per category) repeated 5x each = 100 ideas ---
        $templates = $this->ideaTemplates();
        $statusPool = ['new', 'under_review', 'planned', 'in_progress', 'released', 'not_doing'];

        /** @var Collection<int, Idea> $ideas */
        $ideas = new Collection;

        foreach ($templates as $template) {
            $board = $boards[$template['board']];
            $originalIndex = null;

            for ($repeat = 0; $repeat < 5; $repeat++) {
                $isFirst = $repeat === 0;
                $markDuplicate = ! $isFirst && $originalIndex !== null && fake()->boolean(25);

                $idea = Idea::factory()
                    ->when(fake()->boolean(8), fn ($factory) => $factory->anonymous())
                    ->when(fake()->boolean(12), fn ($factory) => $factory->private())
                    ->create([
                        'team_id' => $team->id,
                        'board_group_id' => $board->board_group_id,
                        'board_id' => $board->id,
                        'category_id' => $categories[$template['board']][$template['category']]->id,
                        'submitted_by_user_id' => $contributors->random()->id,
                        'title' => $template['title'],
                        'slug' => Str::slug($template['title']).'-'.($ideas->count() + 1),
                        'description' => $template['description'],
                        'status' => $markDuplicate ? 'duplicate' : ($isFirst ? $template['status'] : fake()->randomElement($statusPool)),
                        'priority' => $isFirst ? $template['priority'] : fake()->randomElement(['low', 'medium', 'high']),
                        'impact' => $isFirst ? $template['impact'] : fake()->randomElement(['low', 'medium', 'high']),
                        'effort' => $isFirst ? $template['effort'] : fake()->randomElement(['small', 'medium', 'large']),
                    ]);

                if ($markDuplicate) {
                    $idea->update(['duplicate_of_idea_id' => $ideas[$originalIndex]->id]);
                }

                $ideas->push($idea);

                if ($isFirst) {
                    $originalIndex = $ideas->count() - 1;
                }
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

        // --- Comments: exactly 50 total, spread as single comments and small threads ---
        $commentBudget = 50;
        $commentPool = $ideas->shuffle()->values();
        $remaining = $commentBudget;
        $ideaIndex = 0;

        while ($remaining > 0 && $ideaIndex < $commentPool->count()) {
            $threadSize = min($remaining, fake()->randomElement([1, 1, 1, 2, 2, 3]));
            $idea = $commentPool[$ideaIndex];

            for ($i = 0; $i < $threadSize; $i++) {
                if (fake()->boolean(20)) {
                    IdeaComment::factory()->internal()->create([
                        'idea_id' => $idea->id,
                        'user_id' => $reviewers->random()->id,
                    ]);
                } else {
                    IdeaComment::factory()->create([
                        'idea_id' => $idea->id,
                        'user_id' => $allUsers->random()->id,
                    ]);
                }
            }

            $remaining -= $threadSize;
            $ideaIndex++;
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
        $this->command?->info('Sample logins (all password: "password"):');
        $this->command?->info('  Owner:   aarona@ellisontravel.com');
        $this->command?->info('  Admin:   admin@ellisontravel.com');
        $this->command?->info('  Manager: manager@ellisontravel.com');
        $this->command?->info('  Viewer:  viewer@ellisontravel.com');
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
     * Realistic sample idea templates for a travel business improvement portal.
     * Two templates per category (10 categories = 20 templates); each is
     * repeated 5x in run() to reach 100 ideas, with later repeats getting
     * randomized status/priority/impact/effort and some marked as duplicates.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ideaTemplates(): array
    {
        return [
            // operations / process-improvement
            [
                'title' => 'Reduce duplicate data entry between our booking system and accounting',
                'board' => 'operations',
                'category' => 'process-improvement',
                'status' => 'under_review',
                'priority' => 'high',
                'impact' => 'medium',
                'effort' => 'medium',
                'description' => 'The same booking details are keyed into our booking system and then again into the accounting system. A one-way sync would remove double entry and mismatches.',
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
            // operations / customer-service
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
                'title' => 'Proactive check-in reminders for clients ahead of departure',
                'board' => 'operations',
                'category' => 'customer-service',
                'status' => 'new',
                'priority' => 'medium',
                'impact' => 'medium',
                'effort' => 'small',
                'description' => 'Clients often forget to complete online check-in before their flight, leading to last-minute panic calls to the office. Automated reminders a few days out would cut down on these calls.',
            ],
            // operations / reporting
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
                'title' => 'Weekly operations KPI snapshot for leadership',
                'board' => 'operations',
                'category' => 'reporting',
                'status' => 'under_review',
                'priority' => 'medium',
                'impact' => 'medium',
                'effort' => 'medium',
                'description' => 'Leadership currently asks for ad-hoc numbers on bookings, refunds, and response times. A short automated weekly snapshot would save the team from rebuilding the same report by hand.',
            ],
            // accounting / automation
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
                'title' => 'Automated reminders for outstanding client payments',
                'board' => 'accounting',
                'category' => 'automation',
                'status' => 'in_progress',
                'priority' => 'high',
                'impact' => 'high',
                'effort' => 'medium',
                'description' => 'Overdue balances are tracked in a spreadsheet and chased manually. Scheduled reminder emails at set intervals would improve cash flow.',
            ],
            // accounting / reporting
            [
                'title' => 'Central dashboard for trip profitability',
                'board' => 'accounting',
                'category' => 'reporting',
                'status' => 'under_review',
                'priority' => 'high',
                'impact' => 'high',
                'effort' => 'large',
                'description' => 'Managers cannot easily see margin per trip. A profitability dashboard would help prioritise the most valuable products.',
            ],
            [
                'title' => 'Automated month-end close checklist',
                'board' => 'accounting',
                'category' => 'reporting',
                'status' => 'planned',
                'priority' => 'high',
                'impact' => 'medium',
                'effort' => 'small',
                'description' => 'Month-end close relies on one person remembering every step from memory. A shared checklist with automated reminders would reduce the risk of a missed reconciliation.',
            ],
            // accounting / cost-savings
            [
                'title' => 'Renegotiate merchant processing fees across suppliers',
                'board' => 'accounting',
                'category' => 'cost-savings',
                'status' => 'under_review',
                'priority' => 'medium',
                'impact' => 'high',
                'effort' => 'medium',
                'description' => "We're paying different card-processing rates across suppliers with no consolidated view. Reviewing and renegotiating as a group could meaningfully cut transaction costs.",
            ],
            [
                'title' => 'Consolidate software subscriptions across departments',
                'board' => 'accounting',
                'category' => 'cost-savings',
                'status' => 'new',
                'priority' => 'low',
                'impact' => 'medium',
                'effort' => 'small',
                'description' => 'Several teams pay for overlapping tools separately. An audit of active subscriptions could cut unnecessary spend and simplify renewals.',
            ],
            // technology / software-request
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
                'title' => 'Mobile-friendly expense submission for tour leaders',
                'board' => 'technology',
                'category' => 'software-request',
                'status' => 'not_doing',
                'priority' => 'low',
                'impact' => 'low',
                'effort' => 'medium',
                'description' => 'Tour leaders want to submit expenses from their phones on the road. Reviewed but deferred in favour of higher-impact tooling this year.',
            ],
            // technology / automation
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
                'title' => 'Automate commission report generation',
                'board' => 'technology',
                'category' => 'automation',
                'status' => 'new',
                'priority' => 'low',
                'impact' => 'medium',
                'effort' => 'small',
                'description' => 'Generate the weekly commission report automatically instead of building it by hand, feeding off the same data as the reconciliation tool.',
            ],
            // website / software-request
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
                'title' => 'Add live chat widget to the booking site',
                'board' => 'website',
                'category' => 'software-request',
                'status' => 'planned',
                'priority' => 'medium',
                'impact' => 'high',
                'effort' => 'medium',
                'description' => 'Visitors abandon the booking flow when they have a quick question and no one to ask. A live chat widget during business hours could recover some of those bookings.',
            ],
            // website / customer-service
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
                'title' => 'Post-trip feedback survey automation',
                'board' => 'website',
                'category' => 'customer-service',
                'status' => 'new',
                'priority' => 'low',
                'impact' => 'medium',
                'effort' => 'small',
                'description' => 'Feedback surveys currently go out manually and inconsistently. An automatic survey triggered a day after return would give us more reliable data to act on.',
            ],
        ];
    }
}
