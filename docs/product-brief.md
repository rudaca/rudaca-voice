# Rudaca Voice - Product Brief

## Overview

Rudaca Voice is an idea-management and employee feedback platform.

It is inspired by tools like UserVoice, Aha Ideas, Canny, and Fider, but the goal is broader than software feature requests.

The purpose is to help businesses collect improvement ideas from employees, organize them, allow voting and comments, and turn approved ideas into real action.

## Positioning

Rudaca Voice is not just a feature voting tool.

It is intended to help companies collect employee ideas and convert useful ideas into business improvements.

Examples of ideas:
- Improve an internal process
- Request a software/reporting change
- Suggest an automation
- Reduce duplicate work
- Improve onboarding
- Improve customer service
- Suggest an operational change
- Improve communication between departments

## Target Users

Initial users:
- Business owners
- Managers
- Employees
- Internal IT/software teams

Possible future customers:
- Small and medium-sized businesses
- Companies that want employee ideas but do not need a complex product management tool
- Companies that want an internal suggestion box with voting, prioritization, and follow-through

## Core Workflow

Employee submits idea
→ Other employees vote/comment
→ Manager reviews idea
→ Manager updates status
→ Approved ideas can become real tasks later
→ Employees can see what happened to ideas

## Product Goals

- Make it easy for employees to submit useful ideas
- Let employees vote and comment
- Help managers prioritize ideas
- Track status clearly
- Avoid ideas getting lost in emails, chats, or meetings
- Show employees that ideas are being reviewed and acted on

## What Makes This Different

Most similar tools focus on customer product feedback and software feature requests.

Rudaca Voice should focus on broader business improvement:
- Operations
- HR/processes
- Technology
- Customer service
- Finance/accounting workflows
- Internal communication
- Automation requests
- Company improvement ideas

## Technical Direction

Initial technology stack:
- Laravel
- Livewire
- Tailwind
- MySQL or MariaDB
- Laravel queues/jobs later
- GitHub integration later

## Important Product Rule

Do not automatically create GitHub issues for every idea.

Ideas should first be reviewed inside Rudaca Voice. Only after review should a manager/admin create a GitHub issue or other task.

Idea portal = business/user language  
GitHub issue = approved technical work