// =====================================================================
// Rudaca Voice - Database Schema (DBML)
//
// This diagram is reconciled with the committed Laravel Livewire
// starter kit code. Tables in the "EXISTING STARTER KIT" section are
// ALREADY migrated + modelled. Do NOT recreate them.
//
// Role values (stored as a string on team_members.role):
//   owner, admin, manager, employee, viewer
//   NOTE: the shipped App\Enums\TeamRole enum currently only defines
//   owner/admin/member. Manager/Employee/Viewer must be added to the
//   enum before role-gated features are built. Keep permissions simple
//   for now (role string only, no ACL package).
// =====================================================================


// =====================================================================
// SECTION 1 — MVP ACTIVE TABLES
// =====================================================================

// ---------------------------------------------------------------------
// EXISTING STARTER KIT TABLES (already migrated + modelled — do NOT recreate)
//   Models: User, Team, Membership (table: team_members), TeamInvitation
// ---------------------------------------------------------------------

Table users {
  id bigint [pk, increment]
  name varchar
  email varchar [unique]
  email_verified_at timestamp
  password varchar
  remember_token varchar
  current_team_id bigint [null] // added via add_current_team_id_to_users_table migration
  created_at timestamp
  updated_at timestamp
}

Table teams {
  id bigint [pk, increment]
  name varchar
  slug varchar [unique]
  is_personal boolean [default: false] // starter kit uses is_personal (NOT personal_team)
  deleted_at timestamp [null] // soft deletes
  created_at timestamp
  updated_at timestamp
  // NOTE: no user_id column — ownership is derived from team_members.role = 'owner'
}

Table team_members {
  id bigint [pk, increment]
  team_id bigint
  user_id bigint
  role varchar // owner, admin, manager, employee, viewer
  created_at timestamp
  updated_at timestamp

  indexes {
    (team_id, user_id) [unique]
  }
}

Table team_invitations {
  id bigint [pk, increment]
  code varchar [unique] // starter kit uses code (NOT token), length 64
  team_id bigint
  email varchar
  role varchar // owner, admin, manager, employee, viewer
  invited_by bigint // FK -> users.id
  expires_at timestamp [null]
  accepted_at timestamp [null]
  created_at timestamp
  updated_at timestamp
}

// ---------------------------------------------------------------------
// IDEA DOMAIN (to be built for MVP)
//   Suggested models: IdeaBoardGroup, IdeaBoard, IdeaCategory, Idea,
//                      IdeaVote, IdeaComment, IdeaStatusHistory
// ---------------------------------------------------------------------

Table idea_board_groups {
  id bigint [pk, increment]
  team_id bigint
  name varchar
  slug varchar
  description text [null]
  sort_order int [default: 0]
  is_active boolean [default: true]
  created_by_user_id bigint
  created_at timestamp
  updated_at timestamp

  indexes {
    (team_id, slug) [unique]
    team_id
  }
}

Table idea_boards {
  id bigint [pk, increment]
  team_id bigint
  board_group_id bigint [null]
  name varchar
  slug varchar
  description text [null]
  visibility varchar // public, internal, restricted, private
  sort_order int [default: 0]
  is_active boolean [default: true]
  created_by_user_id bigint
  created_at timestamp
  updated_at timestamp

  indexes {
    (team_id, slug) [unique]
    team_id
    board_group_id
  }
}

Table idea_categories {
  id bigint [pk, increment]
  team_id bigint
  board_id bigint
  name varchar
  slug varchar
  description text [null]
  sort_order int [default: 0]
  is_active boolean [default: true]
  created_at timestamp
  updated_at timestamp

  indexes {
    (board_id, slug) [unique]
    team_id
    board_id
  }
}

Table ideas {
  id bigint [pk, increment]
  team_id bigint
  board_group_id bigint [null]
  board_id bigint
  category_id bigint [null]
  submitted_by_user_id bigint
  title varchar
  slug varchar // added for clean idea URLs
  description text
  status varchar // new, under_review, planned, in_progress, released, not_doing, duplicate
  priority varchar // low, medium, high
  impact varchar // low, medium, high
  effort varchar // small, medium, large
  is_anonymous boolean [default: false]
  is_private boolean [default: false]
  duplicate_of_idea_id bigint [null]
  deleted_at timestamp [null] // soft deletes
  created_at timestamp
  updated_at timestamp

  indexes {
    (team_id, slug) [unique]
    team_id
    board_group_id
    board_id
    category_id
    submitted_by_user_id
    status
  }
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
  is_internal boolean [default: false] // admin/manager/owner-only comment
  deleted_at timestamp [null] // soft deletes
  created_at timestamp
  updated_at timestamp

  indexes {
    idea_id
    user_id
  }
}

Table idea_status_history {
  id bigint [pk, increment]
  idea_id bigint
  changed_by_user_id bigint
  old_status varchar
  new_status varchar
  note text [null]
  created_at timestamp

  indexes {
    idea_id
    changed_by_user_id
  }
}


// =====================================================================
// SECTION 2 — PHASE 2 / PLANNED TABLES
// (exist in the schema but not necessarily wired into the UI yet)
// =====================================================================

Table idea_github_links {
  id bigint [pk, increment]
  idea_id bigint
  github_owner varchar
  github_repo varchar
  github_issue_number int
  github_issue_url varchar
  github_issue_state varchar // open, closed
  github_issue_status varchar // backlog, ready, in_progress, done
  last_synced_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    idea_id
    (github_owner, github_repo, github_issue_number) [unique]
  }
}

Table idea_board_role_access {
  id bigint [pk, increment]
  board_id bigint
  role varchar // owner, admin, manager, employee, viewer
  created_at timestamp
  updated_at timestamp

  indexes {
    (board_id, role) [unique]
  }
}

Table idea_board_user_access {
  id bigint [pk, increment]
  board_id bigint
  user_id bigint
  access_level varchar // view, contribute, manage
  created_at timestamp
  updated_at timestamp

  indexes {
    (board_id, user_id) [unique]
  }
}


// =====================================================================
// REFERENCES
// =====================================================================

// Users and teams (starter kit)
Ref: users.current_team_id > teams.id
Ref: team_members.team_id > teams.id
Ref: team_members.user_id > users.id
Ref: team_invitations.team_id > teams.id
Ref: team_invitations.invited_by > users.id

// Board groups
Ref: idea_board_groups.team_id > teams.id
Ref: idea_board_groups.created_by_user_id > users.id

// Boards
Ref: idea_boards.team_id > teams.id
Ref: idea_boards.board_group_id > idea_board_groups.id
Ref: idea_boards.created_by_user_id > users.id

// Categories
Ref: idea_categories.team_id > teams.id
Ref: idea_categories.board_id > idea_boards.id

// Ideas
Ref: ideas.team_id > teams.id
Ref: ideas.board_group_id > idea_board_groups.id
Ref: ideas.board_id > idea_boards.id
Ref: ideas.category_id > idea_categories.id
Ref: ideas.submitted_by_user_id > users.id
Ref: ideas.duplicate_of_idea_id > ideas.id

// Votes
Ref: idea_votes.idea_id > ideas.id
Ref: idea_votes.user_id > users.id

// Comments
Ref: idea_comments.idea_id > ideas.id
Ref: idea_comments.user_id > users.id

// Status history
Ref: idea_status_history.idea_id > ideas.id
Ref: idea_status_history.changed_by_user_id > users.id

// GitHub links (Phase 2)
Ref: idea_github_links.idea_id > ideas.id

// Board access (Phase 2)
Ref: idea_board_role_access.board_id > idea_boards.id
Ref: idea_board_user_access.board_id > idea_boards.id
Ref: idea_board_user_access.user_id > users.id
