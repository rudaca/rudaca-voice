Table users {
  id bigint [pk, increment]
  name varchar
  email varchar [unique]
  email_verified_at timestamp
  password varchar
  remember_token varchar
  current_team_id bigint
  created_at timestamp
  updated_at timestamp
}

Table teams {
  id bigint [pk, increment]
  user_id bigint // owner of the team/workspace
  name varchar
  slug varchar [unique]
  personal_team boolean
  created_at timestamp
  updated_at timestamp
}

Table team_user {
  id bigint [pk, increment]
  team_id bigint
  user_id bigint
  role varchar // owner, admin, manager, employee
  created_at timestamp
  updated_at timestamp

  indexes {
    (team_id, user_id) [unique]
  }
}

Table team_invitations {
  id bigint [pk, increment]
  team_id bigint
  email varchar
  role varchar
  token varchar [unique]
  created_at timestamp
  updated_at timestamp

  indexes {
    (team_id, email) [unique]
  }
}

Table idea_board_groups {
  id bigint [pk, increment]
  team_id bigint
  name varchar
  slug varchar
  description text
  sort_order int
  is_active boolean
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
  board_group_id bigint
  name varchar
  slug varchar
  description text
  visibility varchar // public, internal, restricted, private
  sort_order int
  is_active boolean
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
  description text
  sort_order int
  is_active boolean
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
  board_group_id bigint
  board_id bigint
  category_id bigint [null]
  submitted_by_user_id bigint
  title varchar
  description text
  status varchar // new, under_review, planned, in_progress, released, not_doing, duplicate
  priority varchar // low, medium, high
  impact varchar // low, medium, high
  effort varchar // small, medium, large
  is_anonymous boolean
  is_private boolean
  duplicate_of_idea_id bigint [null]
  created_at timestamp
  updated_at timestamp

  indexes {
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
  is_internal boolean // admin/manager-only comment
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
  note text
  created_at timestamp

  indexes {
    idea_id
    changed_by_user_id
  }
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
    idea_id
    (github_owner, github_repo, github_issue_number) [unique]
  }
}

Table idea_board_role_access {
  id bigint [pk, increment]
  board_id bigint
  role varchar // owner, admin, manager, employee
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

Ref: idea_board_role_access.board_id > idea_boards.id

Ref: idea_board_user_access.board_id > idea_boards.id
Ref: idea_board_user_access.user_id > users.id

// Users and teams
Ref: users.current_team_id > teams.id
Ref: teams.user_id > users.id

// Team membership and invitations
Ref: team_user.team_id > teams.id
Ref: team_user.user_id > users.id
Ref: team_invitations.team_id > teams.id

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

// GitHub links
Ref: idea_github_links.idea_id > ideas.id