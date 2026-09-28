<?php

namespace App\Support\Security;

/** Людська назва пристрою з User-Agent: «iPhone · Safari», «Windows · Chrome». */
class DeviceLabel
{
    public static function fromUserAgent(?string $ua): string
    {
        $ua = (string) $ua;
        if ($ua === '') return 'невідомий пристрій';

        $os = match (true) {
            str_contains($ua, 'iPhone')    => 'iPhone',
            str_contains($ua, 'iPad')      => 'iPad',
            str_contains($ua, 'Android')   => 'Android',
            str_contains($ua, 'Windows')   => 'Windows',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux')     => 'Linux',
            default                        => 'інша ОС',
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/')                                     => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')        => 'Opera',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS')    => 'Firefox',
            str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/')     => 'Chrome',
            str_contains($ua, 'Safari/')                                   => 'Safari',
            default                                                        => 'браузер',
        };

        return "{$os} · {$browser}";
    }
}
