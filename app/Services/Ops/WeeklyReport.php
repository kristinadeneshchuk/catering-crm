<?php

namespace App\Services\Ops;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Тижневий звіт операційного директора — текст для Telegram по розділах.
 * Цифри рахують WeeklyNumbers і ManagerPerformance, тут лише оформлення.
 */
class WeeklyReport
{
    public function __construct(
        private WeeklyNumbers $numbers,
        private ManagerPerformance $managers,
    ) {
    }

    /** @return array<int, string> повідомлення по порядку, кожне ≤ 3 800 символів */
    public function build(CarbonInterface $from, CarbonInterface $to): array
    {
        $n = $this->numbers->forWeek($from, $to);
        $m = $this->managers->forWeek($from, $to);

        $sections = array_filter([
            $this->headline($n, $m),
            $this->volume($n),
            $this->managersSection($m),
            $this->failures($m),
            $this->kitchen($n),
            $this->logistics($n),
            $this->money($n),
        ]);

        return array_merge(...array_map(fn ($s) => $this->chunk($s), $sections));
    }

    private function headline(array $n, array $m): string
    {
        $v = $n['volume'];
        $lines = [
            '📊 <b>Тиждень '.$this->period($n['period']).' · Головне</b>',
            '• Раціонів '.$v['now']['portions'].$this->delta($v['now']['portions'], $v['prev']['portions']).
                ', виручка '.$this->money_($v['now']['revenue']).$this->delta($v['now']['revenue'], $v['prev']['revenue']),
            '• Нових клієнтів '.$n['clients']['now']['new'].', пробників '.$n['clients']['now']['trials']
                .' (минулого тижня '.$n['clients']['prev']['new'].')',
            '• Продовжили '.$n['renewals']['now']['renewed'].' із '.$n['renewals']['now']['ended']
                .($n['renewals']['now']['rate'] !== null ? ' — '.$n['renewals']['now']['rate'].'%' : '')
                .($n['renewals']['prev']['rate'] !== null ? ' (було '.$n['renewals']['prev']['rate'].'%)' : ''),
        ];

        if (! empty($n['kitchen']['per_portion'])) {
            $k = $n['kitchen'];
            $lines[] = '• Кухня '.$k['per_portion'].' ₴ на порцію при нормі '.(int) $k['limit']
                .($k['per_portion'] > $k['limit'] ? ' ⚠️' : ' ✅');
        }

        if ($m['inbox_available'] && $m['team']['median_minutes'] !== null) {
            $lines[] = '• Менеджери відповідають у середньому за '.$m['team']['median_minutes'].' хв, без відповіді '.$m['team']['unanswered'].' чатів';
        }

        $lines[] = '• Факапів за тиждень: '.count($m['failures']);
        $lines[] = '• Закінчуються '.$this->period(['from' => $n['expiring']['from'], 'to' => $n['expiring']['till']]).': '
            .$n['expiring']['orders'].' замовлень у '.$n['expiring']['clients'].' клієнтів — писати сьогодні';

        return implode("\n", $lines);
    }

    private function volume(array $n): string
    {
        $v     = $n['volume'];
        $names = ['avocado_food' => 'Avocado', 'u_fit' => 'U-Fit', 'afood' => 'A-Food'];
        $brands = collect($v['now']['by_brand'])->map(fn ($p, $code) => ($names[$code] ?? $code).' '.$p
            .$this->delta($p, $v['prev']['by_brand'][$code] ?? 0))->implode(' · ');

        $days = collect($n['by_day'])->map(fn ($p, $d) => $this->weekday($d).' '.$p)->implode(', ');

        return "<b>1. Обсяг і клієнти</b>\n"
            ."Раціонів {$v['now']['portions']} (було {$v['prev']['portions']}) · клієнтів {$v['now']['clients']} · індивідуальних {$v['now']['individual']}\n"
            ."{$brands}\n"
            ."По днях: {$days}\n"
            ."Нових {$n['clients']['now']['new']}, пробників {$n['clients']['now']['trials']}. "
            ."Закінчилось {$n['renewals']['now']['ended']}, продовжили {$n['renewals']['now']['renewed']}.";
    }

    private function managersSection(array $m): ?string
    {
        if ($m['managers'] === []) {
            return "<b>2. Менеджери</b>\nДаних за тиждень немає.";
        }

        $lines = ['<b>2. Менеджери</b> (бали 0–100: швидкість 35, покриття 15, продажі 30, помилки 20)'];

        if (! $m['inbox_available']) {
            $lines[] = 'Чати Inbox недоступні — час відповіді не порахований, бали лише за продажі й помилки.';
        }

        foreach ($m['managers'] as $name => $x) {
            $row = ['<b>'.e($name).'</b> — '.$x['score'].' балів'];

            if ($x['median_minutes'] !== null) {
                $row[] = 'відповідь '.round($x['median_minutes']).' хв (90% до '.round($x['p90_minutes']).'), в SLA '.$x['within_sla'].'%';
            }

            if ($x['chats'] > 0 || $x['unanswered'] > 0) {
                $row[] = 'чатів '.$x['chats'].($x['unanswered'] ? ', без відповіді '.$x['unanswered'] : '');
            }

            if ($x['orders_created'] > 0) {
                $row[] = 'замовлень '.$x['orders_created'].' на '.$this->money_($x['orders_sum']).($x['trials'] ? ', пробників '.$x['trials'] : '');
            }

            if ($x['renewal_rate'] !== null) {
                $row[] = 'продовження '.$x['renewed'].' із '.$x['ended'].' ('.$x['renewal_rate'].'%)';
            }

            if ($x['payments'] > 0) {
                $row[] = 'підтвердив(ла) оплат '.$x['payments'].' на '.$this->money_($x['payments_sum']);
            }

            if ($x['failures'] > 0) {
                $row[] = 'факапів '.$x['failures'];
            }

            $lines[] = '• '.implode(' · ', $row);
        }

        return implode("\n", $lines);
    }

    private function failures(array $m): ?string
    {
        if ($m['failures'] === []) {
            return "<b>3. Факапи</b>\nЧистий тиждень ✅";
        }

        $lines = ['<b>3. Факапи тижня</b> ('.count($m['failures']).')'];

        foreach (array_slice($m['failures'], 0, 12) as $f) {
            $lines[] = '• '.e($f['manager']).': '.e($f['text']).($f['link'] ? ' — <a href="'.e($f['link']).'">чат</a>' : '');
        }

        if (count($m['failures']) > 12) {
            $lines[] = '… і ще '.(count($m['failures']) - 12);
        }

        return implode("\n", $lines);
    }

    private function kitchen(array $n): ?string
    {
        $k = $n['kitchen'];

        if (empty($k['days'])) {
            return null;
        }

        $lines = ['<b>4. Кухня</b> · ФОТ '.$this->money_($k['fot']).' на '.$k['portions'].' порцій = <b>'.$k['per_portion'].' ₴/порція</b> (норма '.(int) $k['limit'].')'];

        foreach ($k['days'] as $date => $d) {
            $flag = $d['per_portion'] !== null && $d['per_portion'] > $k['limit'] ? ' ⚠️' : '';
            $lines[] = '• '.$this->weekday($date).' '.Carbon::parse($date)->format('d.m').': '.$d['people'].' люд., '
                .$this->money_($d['fot']).' на '.$d['portions'].' порцій → '.($d['per_portion'] ?? '—').' ₴'.$flag;
        }

        if (! empty($n['ratings']['count'])) {
            $r = $n['ratings'];
            $lines[] = 'Оцінки: '.$r['count'].', середня '.$r['avg'].($r['bad'] ? ', низьких '.$r['bad'] : '');

            foreach ($r['comments'] as $c) {
                $lines[] = '  ↳ '.e($c->name).' ('.$c->stars.'★): «'.e(mb_substr((string) $c->comment, 0, 120)).'»';
            }
        }

        return implode("\n", $lines);
    }

    private function logistics(array $n): ?string
    {
        $c = $n['couriers']['now'];
        $p = $n['couriers']['prev'];

        if ($c['routes'] === 0 && $c['km'] === 0) {
            return null;
        }

        return "<b>5. Логістика</b>\n"
            ."Маршрутів {$c['routes']} · точок {$c['stops']} · км {$c['km']}".$this->delta($c['km'], $p['km'])."\n"
            .'Пальне й амортизація '.$this->money_($c['compensation']).$this->delta($c['compensation'], $p['compensation'])
            .' · ставки '.$this->money_($c['pay'])."\n"
            .'Разом доставка '.$this->money_($c['total']).($c['per_stop'] ? ', '.$c['per_stop'].' ₴ на точку' : '')
            .($p['per_stop'] ? ' (було '.$p['per_stop'].')' : '');
    }

    private function money(array $n): ?string
    {
        $m = $n['money'];
        $pu = $n['purchases']['now'] ?? [];

        $lines = ['<b>6. Гроші</b>'];
        $lines[] = 'Надійшло від клієнтів '.$this->money_($m['paid_in']).' за '.$m['payments'].' платежів'.$this->delta($m['paid_in'], $m['paid_in_prev']);
        $lines[] = 'Неоплачених активних '.$m['unpaid'].' на '.$this->money_($m['unpaid_sum']).' · боржників '.$m['debtors'].' на '.$this->money_($m['debt']);

        if ($pu !== []) {
            $suppliers = collect($pu['by_supplier'])->map(fn ($s) => e($s->supplier).' '.$this->money_($s->sum))->implode(', ');
            $lines[] = 'Закупівлі: '.$pu['docs'].' накладних на '.$this->money_($pu['sum']).($suppliers ? ' — '.$suppliers : '');

            if ($pu['drafts'] > 0) {
                $lines[] = '⚠️ Чернеток накладних не проведено: '.$pu['drafts'].' на '.$this->money_($pu['drafts_sum']);
            }
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------

    private function delta(float|int $now, float|int $prev): string
    {
        if ($prev <= 0) {
            return '';
        }

        $pct = round(($now - $prev) / $prev * 100);

        return ' ('.($pct >= 0 ? '+' : '').$pct.'%)';
    }

    private function money_(float|int|null $v): string
    {
        return number_format((float) $v, 0, ',', ' ').' ₴';
    }

    private function period(array $p): string
    {
        return Carbon::parse($p['from'])->format('d.m').'–'.Carbon::parse($p['to'])->format('d.m');
    }

    private function weekday(string $date): string
    {
        return ['нд', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'][Carbon::parse($date)->dayOfWeek];
    }

    /** @return array<int, string> */
    private function chunk(string $text, int $limit = 3800): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $parts = [];
        $buf   = '';

        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($buf) + mb_strlen($line) + 1 > $limit) {
                $parts[] = $buf;
                $buf     = '';
            }

            $buf .= ($buf === '' ? '' : "\n").$line;
        }

        $parts[] = $buf;

        return $parts;
    }
}
