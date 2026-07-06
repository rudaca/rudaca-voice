# Ideas Portal

Ideas Portal is a Laravel/Livewire application for collecting, reviewing, prioritizing, and tracking ideas inside an organization.

The goal is to give employees a simple place to submit improvement ideas, vote on ideas, comment, and see what is being reviewed, planned, or completed. For managers and administrators, the platform provides a structured way to review suggestions, prioritize work, and eventually connect approved ideas to execution tools such as GitHub, Microsoft Planner, Jira, or other task management systems.

This project is inspired by products such as UserVoice, Aha Ideas, Canny, and internal suggestion-box tools, but with a broader business focus. It is not only for software feature requests. It is meant to support business improvement ideas across departments such as operations, finance, HR, IT, customer service, sales, marketing, and internal systems.

---

## Project Goal

The main goal is to help companies move from scattered employee suggestions to a structured idea-management process.

Instead of ideas getting lost in emails, chats, meetings, or informal conversations, the platform provides a central place where ideas can be submitted, discussed, voted on, reviewed, prioritized, and tracked.

The first version should focus on a simple but useful workflow:

```text
Employee submits idea
        ↓
Other users vote and comment
        ↓
Manager/admin reviews the idea
        ↓
Idea status is updated
        ↓
Approved ideas can later become real work items
```

---

## Product Vision

The long-term vision is to create a business-friendly internal ideas platform for small and medium-sized companies.

Many existing feedback tools are built mainly for software companies collecting customer product feedback. This project is different. It focuses on employee and business improvement ideas.

Examples of ideas this system should support:

* Improve an internal workflow
* Automate a repetitive task
* Request a new report
* Suggest a software improvement
* Improve onboarding
* Reduce duplicate data entry
* Improve customer experience
* Suggest a process change
* Report a recurring operational pain point
* Propose a cost-saving idea
* Recommend a better approval process
* Suggest a new internal tool

The product should help leadership understand not only what people want, but also why it matters, how many people support it, what department it affects, how difficult it may be, and whether it became real action.

---

## MVP Scope

The MVP should stay small and focused.

### Included in MVP

* User registration and login
* Organizations
* Organization memberships
* User roles
* Idea boards
* Idea categories
* Idea submission
* Idea list
* Idea detail page
* Voting
* Comments
* Internal/admin comments
* Idea status workflow
* Status history
* Admin review screen
* Basic priority, impact, and effort fields

### Not Included in MVP

These items should be considered later and should not block the first working version:

* Billing/subscriptions
* AI duplicate detection
* AI summarization
* GitHub automatic sync
* Microsoft Planner integration
* Jira integration
* Teams/Slack notifications
* Public roadmap
* Changelog
* Mobile app
* Advanced analytics
* Custom workflows
* SSO/SAML
* Multi-language support

---

## Suggested Tech Stack

* Laravel
* Livewire
* Tailwind CSS
* Alpine.js
* MySQL or MariaDB
* Laravel queues for background jobs
* Laravel notifications for future email/Teams alerts
* Laravel HTTP client for future integrations
* Optional: Spatie Laravel Permission for advanced roles later

The first version can use a simple role field on the organization membership table instead of a full permissions package.

---

## Core Concepts

### Organization

An organization represents a company, customer, or workspace.

If this product becomes a SaaS, each customer would have its own organization.

Examples:

* Ellison Travel
* ABC Manufacturing
* XYZ Insurance

### User

A user is a person using the system.

A user can belong to one or more organizations.

### Organization User

This table connects users to organizations and defines the user’s role within that organization.

Example roles:

* Owner
* Admin
* Manager
* Employee
* Viewer

### Board

A board is a place where ideas are grouped.

Examples:

* Atlas
* Tour Leader App
* Website
* Operations
* Accounting
* HR
* Internal Tools
* Customer Experience

### Category

A category helps classify ideas inside a board.

Examples:

* Reporting
* Automation
* Process Improvement
* Bug / Issue
* New Feature
* Training
* Cost Saving
* Customer Experience

### Idea

An idea is the main item submitted by a user.

An idea can include:

* Title
* Description
* Board
* Category
* Status
* Priority
* Impact
* Effort
* Anonymous flag
* Private flag
* Submitter
* Votes
* Comments

### Vote

A vote means a user supports an idea.

Each user can vote only once per idea.

### Comment

Comments allow discussion around an idea.

Comments may be public to the organization or internal-only for managers/admins.

### Status History

Every time an idea changes status, the change should be recorded.

This allows the company to understand the decision history.

### GitHub Link

Approved ideas may later be linked to GitHub issues.

The idea portal should remain the business-facing source of truth. GitHub should be used as the execution system for technical work.

---

## Suggested Status Workflow

The first version should use a simple workflow:

```text
New
Under Review
Planned
In Progress
Released
Not Doing
Duplicate
```

### Status Definitions

#### New

The idea has been submitted but not reviewed yet.

#### Under Review

A manager or admin is reviewing the idea.

#### Planned

The idea has been accepted and is expected to be worked on.

#### In Progress

Work has started.

#### Released

The idea has been completed, implemented, or delivered.

#### Not Doing

The idea has been reviewed but will not be pursued.

#### Duplicate

The idea is similar to another existing idea.

---

## Suggested User Roles

### Owner

Can manage everything in the organization.

Permissions:

* Manage organization settings
* Manage users
* Manage boards
* Manage categories
* Manage all ideas
* Change statuses
* Delete ideas/comments if needed

### Admin

Can manage most operational settings.

Permissions:

* Manage boards
* Manage categories
* Review ideas
* Change statuses
* Moderate comments

### Manager

Can review and prioritize ideas.

Permissions:

* View ideas
* Comment
* Add internal comments
* Update idea status
* Set priority, impact, and effort

### Employee

Can submit and participate.

Permissions:

* Submit ideas
* Vote on ideas
* Comment on ideas
* View visible boards and ideas

### Viewer

Read-only access.

Permissions:

* View visible boards and ideas
* No voting or commenting

---

## Database Schema

The following DBML can be used with dbdiagram.io to visualize the first version of the database.

```dbml
Table users {
  id bigint [pk, increment]
  name varchar
  email varchar [unique]
  email_verified_at timestamp
  password varchar
  remember_token varchar
  created_at timestamp
  updated_at timestamp
}

Table organizations {
  id bigint [pk, increment]
  name varchar
  slug varchar [unique]
  owner_user_id bigint
  created_at timestamp
  updated_at timestamp
}

Table organization_user {
  id bigint [pk, increment]
  organization_id bigint
  user_id bigint
  role varchar // owner, admin, manager, employee, viewer
  created_at timestamp
  updated_at timestamp

  indexes {
    (organization_id, user_id) [unique]
  }
}

Table idea_boards {
  id bigint [pk, increment]
  organization_id bigint
  name varchar
  slug varchar
  description text
  visibility varchar // internal, public, private
  created_at timestamp
  updated_at timestamp

  indexes {
    (organization_id, slug) [unique]
  }
}

Table idea_categories {
  id bigint [pk, increment]
  organization_id bigint
  board_id bigint
  name varchar
  created_at timestamp
  updated_at timestamp
}

Table ideas {
  id bigint [pk, increment]
  organization_id bigint
  board_id bigint
  category_id bigint
  submitted_by_user_id bigint
  title varchar
  description text
  status varchar // new, under_review, planned, in_progress, released, not_doing, duplicate
  priority varchar // low, medium, high
  impact varchar // low, medium, high
  effort varchar // small, medium, large
  is_anonymous boolean
  is_private boolean
  created_at timestamp
  updated_at timestamp
}

Table idea_votes {
  id bigint [pk, increment]
  idea_id bigint
  user_id bigint
  created_at timestamp
  updated_at timestamp

  indexes {
    (idea_id, user_id) [unique]
  }
}

Table idea_comments {
  id bigint [pk, increment]
  idea_id bigint
  user_id bigint
  body text
  is_internal boolean // true for admin/manager-only comments
  created_at timestamp
  updated_at timestamp
}

Table idea_status_history {
  id bigint [pk, increment]
  idea_id bigint
  changed_by_user_id bigint
  old_status varchar
  new_status varchar
  note text
  created_at timestamp
}

Table idea_github_links {
  id bigint [pk, increment]
  idea_id bigint
  github_owner varchar
  github_repo varchar
  github_issue_number int
  github_issue_url varchar
  github_issue_state varchar // open, closed
  github_issue_status varchar // backlog, ready, in_progress, done
  last_synced_at timestamp
  created_at timestamp
  updated_at timestamp

  indexes {
    (github_owner, github_repo, github_issue_number) [unique]
  }
}

// Organizations
Ref: organizations.owner_user_id > users.id

// Organization memberships
Ref: organization_user.organization_id > organizations.id
Ref: organization_user.user_id > users.id

// Boards and categories
Ref: idea_boards.organization_id > organizations.id
Ref: idea_categories.organization_id > organizations.id
Ref: idea_categories.board_id > idea_boards.id

// Ideas
Ref: ideas.organization_id > organizations.id
Ref: ideas.board_id > idea_boards.id
Ref: ideas.category_id > idea_categories.id
Ref: ideas.submitted_by_user_id > users.id

// Votes
Ref: idea_votes.idea_id > ideas.id
Ref: idea_votes.user_id > users.id

// Comments
Ref: idea_comments.idea_id > ideas.id
Ref: idea_comments.user_id > users.id

// Status history
Ref: idea_status_history.idea_id > ideas.id
Ref: idea_status_history.changed_by_user_id > users.id

// GitHub links
Ref: idea_github_links.idea_id > ideas.id
```

---

## Suggested Laravel Models

The MVP should include the following models:

```text
User
Organization
OrganizationUser
IdeaBoard
IdeaCategory
Idea
IdeaVote
IdeaComment
IdeaStatusHistory
IdeaGithubLink
```

### Suggested Relationships

#### User

* belongs to many organizations
* has many submitted ideas
* has many votes
* has many comments

#### Organization

* belongs to owner user
* has many users through organization_user
* has many boards
* has many categories
* has many ideas

#### IdeaBoard

* belongs to organization
* has many categories
* has many ideas

#### IdeaCategory

* belongs to organization
* belongs to board
* has many ideas

#### Idea

* belongs to organization
* belongs to board
* belongs to category
* belongs to submitter user
* has many votes
* has many comments
* has many status history records
* has one or many GitHub links

#### IdeaVote

* belongs to idea
* belongs to user

#### IdeaComment

* belongs to idea
* belongs to user

#### IdeaStatusHistory

* belongs to idea
* belongs to user as changed_by_user

#### IdeaGithubLink

* belongs to idea

---

## Suggested Pages

### Public / Auth Pages

* Login
* Register
* Forgot password
* Reset password
* Email verification
* Profile/settings

These should come from the Laravel starter kit where possible.

### Organization Pages

* Organization dashboard
* Organization settings
* Manage users
* Manage roles

### Board Pages

* Board list
* Board detail
* Create board
* Edit board
* Manage board categories

### Idea Pages

* Idea list
* Idea detail
* Submit idea
* Edit own idea
* Vote/unvote
* Comment
* Search/filter ideas

### Admin / Manager Pages

* Review queue
* Idea review detail
* Change status
* Set priority
* Set impact
* Set effort
* Add internal comment
* View status history

### Future Integration Pages

* GitHub integration settings
* Connected repositories
* Create GitHub issue from idea
* GitHub sync logs

---

## MVP User Flow

### Employee Flow

```text
Employee logs in
        ↓
Selects organization/board
        ↓
Views existing ideas
        ↓
Searches for similar idea
        ↓
Submits new idea if needed
        ↓
Other users vote/comment
        ↓
Employee receives updates when status changes
```

### Manager Flow

```text
Manager logs in
        ↓
Opens review queue
        ↓
Reads new ideas
        ↓
Checks votes/comments
        ↓
Sets impact/effort/priority
        ↓
Updates status
        ↓
Adds internal note if needed
```

### Future GitHub Flow

```text
Manager approves idea
        ↓
Manager clicks "Create GitHub Issue"
        ↓
System creates GitHub issue
        ↓
Idea stores GitHub issue number and URL
        ↓
GitHub webhook updates idea status later
```

---

## GitHub Integration Strategy

GitHub should not be the first feature built. The idea workflow should work without GitHub.

When GitHub integration is added, it should be manager/admin controlled.

The system should not automatically create GitHub issues for every submitted idea. That would create noise and reduce the value of GitHub as a development tracking tool.

Recommended flow:

```text
Idea submitted
        ↓
Idea reviewed
        ↓
Manager decides it is actionable
        ↓
Manager creates GitHub issue from the idea
        ↓
GitHub issue is linked back to the idea
        ↓
GitHub status updates sync back to the idea
```

### GitHub Data to Store

* GitHub owner
* GitHub repo
* Issue number
* Issue URL
* Issue state
* Issue status
* Last synced date/time

### GitHub Issue Content

When creating a GitHub issue, include:

* Idea title
* Idea description
* Submitted by
* Board
* Category
* Vote count
* Comment summary
* Priority
* Impact
* Effort
* Link back to idea portal

### GitHub Webhooks Later

Future webhook events may update:

* Issue state
* Issue labels
* Issue assigned user
* Issue project status
* Issue closed status
* Pull request merged status

---

## Business-Focused Prioritization

This project should not rely only on voting.

Votes are useful, but popularity is not the same as business value.

The product should support a simple prioritization model:

```text
Votes + Impact + Effort + Manager Decision
```

### Impact

Suggested values:

* Low
* Medium
* High

Impact should represent business value.

Examples:

* Saves time
* Reduces errors
* Improves customer experience
* Reduces risk
* Improves employee experience
* Increases revenue
* Reduces cost

### Effort

Suggested values:

* Small
* Medium
* Large

Effort should represent expected difficulty or cost.

### Priority

Suggested values:

* Low
* Medium
* High

Priority should represent management’s decision after reviewing the idea.

---

## Development Approach

### Recommended Phase 1

Build the basic Laravel/Livewire application:

* Auth
* Organizations
* Roles
* Boards
* Categories
* Ideas
* Votes
* Comments
* Status workflow
* Admin review

### Recommended Phase 2

Improve the internal workflow:

* Better filtering
* Search
* Duplicate handling
* Internal notes
* Notifications
* Status change emails
* Activity log

### Recommended Phase 3

Add GitHub integration:

* GitHub token setup
* Repository selection
* Create GitHub issue from idea
* Store GitHub link
* Webhook listener
* Sync issue status back to idea

### Recommended Phase 4

Add SaaS/product features:

* Billing
* Organization limits
* Custom branding
* Public/private boards
* Custom statuses
* Microsoft Teams notifications
* Microsoft Planner integration
* AI idea summarization
* AI duplicate detection
* Analytics dashboard

---

## Suggested Naming Ideas

Possible project/product names:

* Ideas Portal
* Business Ideas Portal
* Employee Ideas Portal
* VoiceBoard
* IdeaFlow
* ImproveHub
* TeamVoice
* Ruda Voice
* InsightBoard
* SuggestionHub

Working internal project name:

```text
Ideas Portal
```

---

## Local Development Setup

### Requirements

* PHP 8.3 or newer
* Composer
* Node.js and npm
* MySQL or MariaDB
* Laravel CLI
* Git

### Installation

Clone the repository:

```bash
git clone <repository-url>
cd ideas-portal
```

Install PHP dependencies:

```bash
composer install
```

Install JavaScript dependencies:

```bash
npm install
```

Copy the environment file:

```bash
cp .env.example .env
```

Generate application key:

```bash
php artisan key:generate
```

Configure database connection in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ideas_portal
DB_USERNAME=root
DB_PASSWORD=
```

Run migrations:

```bash
php artisan migrate
```

Run the development server:

```bash
php artisan serve
```

Compile frontend assets:

```bash
npm run dev
```

---

## Suggested First Development Tasks

1. Create Laravel app using Livewire starter kit
2. Add organization tables and models
3. Add board and category tables
4. Add idea tables and models
5. Build idea submission form
6. Build idea list page
7. Build idea detail page
8. Add voting
9. Add comments
10. Add manager/admin review page
11. Add status history
12. Add basic filters by status, board, category, and vote count

---

## Suggested Seed Data

Seed the application with one organization and sample boards.

### Organization

```text
Ellison Travel
```

### Boards

```text
Atlas
Tour Leader App
Website
Operations
Accounting
Internal Tools
```

### Categories

```text
Automation
Reporting
Process Improvement
New Feature
Bug / Issue
Training
Customer Experience
Cost Saving
```

### Example Ideas

```text
Add an easier way to search tour documents
Create a better report for upcoming final payments
Allow tour leaders to receive app messages by tour
Improve the website request form
Automate supplier follow-up reminders
Create a dashboard for pending accounting tasks
```

---

## Design Principles

### Keep the first version simple

Avoid overbuilding. The first version should prove that employees will submit useful ideas and managers will review them.

### Business language first

The app should be understandable by non-technical users.

Avoid labels like:

```text
ticket
issue
sprint
backlog
```

Prefer labels like:

```text
idea
review
planned
in progress
completed
not doing
```

### GitHub is an execution tool, not the idea portal

Employees should not need to understand GitHub.

Managers can convert approved ideas into GitHub issues when needed.

### Voting is one signal, not the decision-maker

High votes do not automatically mean high priority.

The system should also consider impact, effort, risk, and leadership decision.

### Make decisions visible

Employees are more likely to submit good ideas if they can see what happened to previous ideas.

Status updates matter.

---

## Open Questions

These should be decided during development or internal testing:

* Should employees be allowed to submit anonymous ideas?
* Should anonymous ideas be visible to admins?
* Should every organization have multiple boards?
* Should boards be public, internal, or private by default?
* Should comments be editable?
* Should users be able to delete their own ideas?
* Should managers be able to merge duplicate ideas?
* Should voting be one vote only, or should users have limited voting credits?
* Should employees receive email notifications on status changes?
* Should the product support public customer-facing boards later?
* Should GitHub integration be per organization or per board?
* Should status values be fixed or customizable?

---

## Future Ideas

### AI Features

* Suggest duplicate ideas before submission
* Summarize long comment threads
* Categorize ideas automatically
* Suggest impact/effort score
* Generate manager summaries
* Generate monthly idea digest
* Generate release notes from completed ideas

### Integrations

* GitHub
* Microsoft Planner
* Microsoft Teams
* Slack
* Jira
* Asana
* Monday.com
* Email notifications
* SharePoint knowledge base

### SaaS Features

* Multi-tenant organizations
* Billing/subscriptions
* Organization limits
* Custom branding
* Custom domains
* Public/private boards
* Role-based permissions
* Audit logs
* Usage analytics

### Analytics

* Ideas submitted by month
* Ideas by board
* Ideas by category
* Most voted ideas
* Completed ideas
* Average review time
* Employee participation
* Estimated time saved
* Estimated cost savings

---

## Definition of Done for MVP

The MVP is complete when:

* Users can register and log in
* Users can belong to an organization
* Admins can create boards and categories
* Employees can submit ideas
* Employees can vote on ideas
* Employees can comment on ideas
* Managers can review ideas
* Managers can update idea status
* Status changes are recorded
* Ideas can be filtered by board, category, and status
* The application can be tested internally with real users

---

## Internal Testing Plan

The first real test should be inside one organization before trying to sell the product externally.

Suggested test period:

```text
30 to 60 days
```

Suggested boards for testing:

```text
Atlas
Tour Leader App
Website
Operations
Accounting
Internal Tools
```

Questions to answer during testing:

* Do employees submit ideas?
* Are the ideas useful?
* Do people vote?
* Do people comment?
* Are managers reviewing ideas?
* Are statuses clear?
* Is the process better than email/chat?
* Do we need anonymous ideas?
* Do we need private boards?
* Do we need GitHub integration immediately?
* Could this be useful for other businesses?

---

## License

License to be decided.

If this remains an internal project, it can stay private.

If this becomes an open-source project, consider:

* MIT License for permissive open source
* AGPL if requiring shared improvements matters
* Private/commercial license if this becomes a SaaS product

---

## Current Status

Planning / early design.

The initial database structure has been drafted and can be visualized in dbdiagram.io.

Next recommended step:

```text
Create Laravel/Livewire project and generate migrations/models from the DBML schema.
```
