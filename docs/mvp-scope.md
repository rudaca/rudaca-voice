# Rudaca Voice - MVP Scope

## MVP Goal

Build a simple internal idea portal where a company can collect ideas from employees, allow voting/comments, and let managers review and update statuses.

The MVP should prove that:
1. Employees will submit useful ideas.
2. Other employees will vote and comment.
3. Managers can review ideas without creating chaos.
4. The status workflow gives visibility and follow-through.

## MVP Features

### Authentication

Use the Laravel Livewire starter kit.

Required:
- Login
- Registration
- Password reset
- Profile/settings from starter kit

### Organizations

The app should support organizations/companies from the beginning.

This allows the product to become SaaS later.

Required:
- Organization record
- Organization users/memberships
- Basic role per membership

### Roles

Initial roles:
- Owner
- Admin
- Manager
- Employee
- Viewer

For MVP, roles can be stored as a string column in the organization_user pivot table.

### Idea Boards

Boards are areas where ideas are submitted.

Examples:
- General Ideas
- Operations
- Technology
- Atlas
- Website
- Tour Leader App
- Accounting

Required:
- Create board
- List boards
- Assign ideas to a board

### Categories

Categories help group ideas within an organization or board.

Examples:
- Process improvement
- Software request
- Automation
- Reporting
- Customer service
- HR/onboarding
- Cost savings

Required:
- Create category
- Assign ideas to category

### Ideas

Required:
- Submit idea
- Title
- Description
- Board
- Category
- Status
- Priority
- Impact
- Effort
- Submitted by user
- Anonymous option
- Private option

### Voting

Required:
- One vote per user per idea
- User can add/remove vote
- Idea list can show vote count

### Comments

Required:
- Employees can comment
- Managers/admins can add internal comments
- Internal comments are visible only to managers/admins/owners

### Status Workflow

Suggested statuses:
- New
- Under Review
- Planned
- In Progress
- Released
- Not Doing
- Duplicate

Required:
- Manager/admin can change status
- Status change is stored in history
- Optional note when changing status

### Admin Review

Required:
- Admin/manager idea list
- Filter by status
- Update status
- Update priority, impact, effort
- View votes/comments

## Not in MVP

Do not build these in the first version:
- Billing
- Stripe subscriptions
- GitHub sync
- GitHub webhooks
- Microsoft Planner integration
- Jira integration
- Teams notifications
- AI duplicate detection
- AI summaries
- Public roadmap
- Changelog
- Mobile app
- Complex multi-tenant package
- Complex permissions package unless needed

## Phase 2 Ideas

Possible later features:
- Create GitHub issue from approved idea
- Sync status from GitHub
- Microsoft Planner integration
- Teams notifications
- Email digests
- Duplicate idea merging
- Roadmap view
- Released/completed ideas page
- Outcome tracking
- Cost/time saved tracking
- Anonymous ideas
- AI duplicate detection
- AI idea summary
- AI categorization
- Billing/subscriptions