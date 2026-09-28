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
     * counts toward a user's one-vote-per-board limit, and this is the set
     * the Board's default "Active" status filter preset shows.
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
     * Declared as a literal constant (rather than derived from activeCases())
     * so it can also be used as a constant expression, e.g. a Livewire
     * property default.
     *
     * @var array<int, string>
     */
    public const array ACTIVE_STATUS_VALUES = ['new', 'approved', 'planned', 'in_progress', 'on_hold'];

    /**
     * The string values of activeCases().
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return self::ACTIVE_STATUS_VALUES;
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

    /**
     * The Board's default "Active" status filter preset values — the current
     * working backlog, excluding every terminal status.
     *
     * @return array<int, string>
     */
    public static function boardDefaultValues(): array
    {
        return self::activeValues();
    }

    /**
     * Every status value, for the Board's "All Statuses" filter preset.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Display order for the Board's status filter dropdown: non-terminal
     * statuses first, then terminal ones below a separator.
     *
     * @return array<int, self>
     */
    public static function boardFilterOrder(): array
    {
        return [
            self::New, self::Approved, self::InProgress, self::OnHold, self::Planned, self::Released,
            self::NotDoing, self::Duplicate, self::Archived,
        ];
    }
}
