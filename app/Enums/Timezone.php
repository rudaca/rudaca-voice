<?php

namespace App\Enums;

enum Timezone: string
{
    case Atikokan = 'America/Atikokan';
    case BlancSablon = 'America/Blanc-Sablon';
    case CambridgeBay = 'America/Cambridge_Bay';
    case Creston = 'America/Creston';
    case Dawson = 'America/Dawson';
    case DawsonCreek = 'America/Dawson_Creek';
    case Edmonton = 'America/Edmonton';
    case FortNelson = 'America/Fort_Nelson';
    case GlaceBay = 'America/Glace_Bay';
    case GooseBay = 'America/Goose_Bay';
    case Halifax = 'America/Halifax';
    case Inuvik = 'America/Inuvik';
    case Iqaluit = 'America/Iqaluit';
    case Moncton = 'America/Moncton';
    case RankinInlet = 'America/Rankin_Inlet';
    case Regina = 'America/Regina';
    case Resolute = 'America/Resolute';
    case StJohns = 'America/St_Johns';
    case SwiftCurrent = 'America/Swift_Current';
    case Toronto = 'America/Toronto';
    case Vancouver = 'America/Vancouver';
    case Whitehorse = 'America/Whitehorse';
    case Winnipeg = 'America/Winnipeg';

    /**
     * Get the display label for the timezone.
     */
    public function label(): string
    {
        return match ($this) {
            self::Atikokan => 'Atikokan',
            self::BlancSablon => 'Blanc Sablon',
            self::CambridgeBay => 'Cambridge Bay',
            self::Creston => 'Creston',
            self::Dawson => 'Dawson',
            self::DawsonCreek => 'Dawson Creek',
            self::Edmonton => 'Edmonton',
            self::FortNelson => 'Fort Nelson',
            self::GlaceBay => 'Glace Bay',
            self::GooseBay => 'Goose Bay',
            self::Halifax => 'Halifax',
            self::Inuvik => 'Inuvik',
            self::Iqaluit => 'Iqaluit',
            self::Moncton => 'Moncton',
            self::RankinInlet => 'Rankin Inlet',
            self::Regina => 'Regina',
            self::Resolute => 'Resolute',
            self::StJohns => 'St Johns',
            self::SwiftCurrent => 'Swift Current',
            self::Toronto => 'Toronto',
            self::Vancouver => 'Vancouver',
            self::Whitehorse => 'Whitehorse',
            self::Winnipeg => 'Winnipeg',
        };
    }

    /**
     * Get the default timezone for new users.
     */
    public static function default(): self
    {
        return self::Toronto;
    }

    /**
     * Get the timezone options for display in a select input, sorted by label.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $timezone) => ['value' => $timezone->value, 'label' => $timezone->label()])
            ->sortBy('label')
            ->values()
            ->toArray();
    }
}
