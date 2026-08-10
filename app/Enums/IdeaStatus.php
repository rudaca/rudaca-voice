<?php

namespace App\Enums;

enum IdeaStatus: string
{
    case New = 'new';
    case Approved = 'approved';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Released = 'released';
    case NotDoing = 'not_doing';
    case Duplicate = 'duplicate';
    case Archived = 'archived';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Approved => 'Approved',
            self::Planned => 'Planned',
            self::InProgress => 'In Progress',
            self::OnHold => 'On Hold',
            self::Released => 'Completed',
            self::NotDoing => 'Declined',
            self::Duplicate => 'Duplicate',
            self::Archived => 'Archived',
        };
    }

    /**
     * Get the Flux badge color used to represent this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::New => 'zinc',
            self::Approved => 'amber',
            self::Planned => 'blue',
            self::InProgress => 'indigo',
            self::OnHold => 'orange',
            self::Released => 'green',
            self::NotDoing => 'red',
            self::Duplicate => 'rose',
            self::Archived => 'zinc',
        };
    }

    /**
     * Get the badge class override for this status, if any.
     *
     * Only needed when the rendered badge color differs from the nominal
     * `color()`, so that `<x-status-dot>` can follow what the badge actually
     * looks like via `dotColor()`.
     */
    public function badgeClass(): ?string
    {
        return match ($this) {
            self::Duplicate => 'bg-red-100! text-red-700! dark:bg-red-900/40! dark:text-red-300!',
            default => null,
        };
    }

    /**
     * Get the dot color override for this status, if any.
     */
    public function dotColor(): ?string
    {
        return match ($this) {
            self::Duplicate => 'red',
            default => null,
        };
    }

    /**
     * Statuses considered "active" — an idea in one of these statuses still
     * counts toward a user's one-vote-per-board limit.
     *
     * @return array<int, self>
     */
    public static function activeCases(): array
    {
        return [self::New, self::Approved, self::Planned, self::InProgress, self::OnHold];
    }

    /**
     * Statuses considered "terminal" — an idea reaching one of these
     * statuses releases any votes attached to it from the per-board limit.
     *
     * @return array<int, self>
     */
    public static function terminalCases(): array
    {
        return [self::Released, self::NotDoing, self::Duplicate, self::Archived];
    }

    /**
     * The string values of activeCases(), for use in whereIn() queries.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::activeCases());
    }

    /**
     * The string values of terminalCases().
     *
     * @return array<int, string>
     */
    public static function terminalValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::terminalCases());
    }

    /**
     * Display metadata for every status, keyed by value — a drop-in
     * replacement for the STATUS_META class constant previously duplicated
     * across the ideas index and show pages.
     *
     * @return array<string, array{label: string, color: string, class?: string, dotColor?: string}>
     */
    public static function meta(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [
            $status->value => array_filter([
                'label' => $status->label(),
                'color' => $status->color(),
                'class' => $status->badgeClass(),
                'dotColor' => $status->dotColor(),
            ], fn ($value) => $value !== null),
        ])->all();
    }
}
