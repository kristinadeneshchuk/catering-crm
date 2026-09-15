<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CourierShiftReportResource\Pages;
use App\Models\CourierShiftReport;
use App\Models\Employee;
use App\Services\Couriers\CourierReportService;
use App\Services\Payments\PaymentClaimService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\URL;

/**
 * Звіти змін курʼєрів на перевірці (docs/tz-ops-agent.md §1.5).
 *
 * Звіт — чернетка: пробіг не записано, баланс не рухався. Адмін бачить км
 * проти плану, позначки й фото і натискає «Підтвердити» (за потреби виправивши
 * цифри) або «Відхилити».
 */
class CourierShiftReportResource extends Resource
{
    protected static ?string $model = CourierShiftReport::class;

    protected static ?string $navigationGroup = 'Система';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Звіти курʼєрів';
    protected static ?string $modelLabel = 'Звіт курʼєра';
    protected static ?string $pluralModelLabel = 'Звіти курʼєрів';

    public static function canAccess(): bool
    {
        return \App\Support\SchemaReady::has('courier_shift_reports')
            && PaymentClaimService::canResolve(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function isAdmin(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! \App\Support\SchemaReady::has('courier_shift_reports')) {
            return null;
        }

        $n = CourierShiftReport::where('status', CourierShiftReport::STATUS_DRAFT)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee'))
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Дата')->date('d.m')->sortable(),
                Tables\Columns\TextColumn::make('employee.name')->label('Курʼєр')->searchable(),
                Tables\Columns\TextColumn::make('shift_slot')->label('Зміна')
                    ->formatStateUsing(fn ($state) => $state === 'evening' ? 'Вечір' : 'Ранок'),
                Tables\Columns\TextColumn::make('route')->label('Маршрут')
                    ->state(fn (CourierShiftReport $r) => implode(', ', array_map(fn ($n) => '№'.$n, $r->expected['route_nums'] ?? [])) ?: '—')
                    ->description(fn (CourierShiftReport $r) => (int) ($r->expected['stops'] ?? 0).' точок'),
                Tables\Columns\TextColumn::make('km')->label('Км')
                    ->state(fn (CourierShiftReport $r) => $r->mileageSummary()['km'] ?? '—')
                    ->description(function (CourierShiftReport $r) {
                        $m = $r->mileageSummary();

                        return $m['plan'] > 0 ? 'план '.(int) round($m['plan']) : ($m['unit'] === 'mi' ? $m['raw'].' mi' : null);
                    }),
                Tables\Columns\TextColumn::make('anomalies')->label('Позначки')
                    ->state(fn (CourierShiftReport $r) => collect($r->anomalies ?? [])->where('severity', '!=', 'info')->count() ?: '')
                    ->badge()
                    ->color(fn (CourierShiftReport $r) => match ($r->worstSeverity()) { 'red' => 'danger', 'yellow' => 'warning', default => 'gray' })
                    ->tooltip(fn (CourierShiftReport $r) => collect($r->anomalies ?? [])->pluck('text')->implode("\n") ?: null),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state) => CourierShiftReport::statusLabels()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        CourierShiftReport::STATUS_DRAFT    => 'warning',
                        CourierShiftReport::STATUS_ACCEPTED => 'success',
                        CourierShiftReport::STATUS_REJECTED => 'danger',
                        default                             => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')
                    ->options(CourierShiftReport::statusLabels())
                    ->default(CourierShiftReport::STATUS_DRAFT),
                Tables\Filters\SelectFilter::make('employee_id')->label('Курʼєр')
                    ->options(fn () => Employee::where('position', 'courier')->orderBy('name')->pluck('name', 'id')),
                Tables\Filters\Filter::make('with_marks')->label('Лише з позначками')
                    ->query(fn ($query) => $query->where(fn ($w) => $w
                        ->where('anomalies', 'like', '%"yellow"%')
                        ->orWhere('anomalies', 'like', '%"red"%'))),
            ])
            ->actions([
                static::reviewAction(),
                Tables\Actions\Action::make('reject')
                    ->label('Відхилити')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (CourierShiftReport $r) => $r->isDraft() && static::isAdmin())
                    ->form([Forms\Components\Textarea::make('reason')->label('Причина')->required()->rows(2)])
                    ->action(function (CourierShiftReport $r, array $data) {
                        app(CourierReportService::class)->reject($r, auth()->id(), $data['reason']);
                        Notification::make()->title('Звіт відхилено')->warning()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('confirm_clean')
                    ->label('Підтвердити без позначок')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn () => static::isAdmin())
                    ->requiresConfirmation()
                    ->modalDescription('Підтвердяться лише чернетки без жовтих і червоних позначок. Решту перевірте по одному.')
                    ->action(function (Collection $records) {
                        $service = app(CourierReportService::class);
                        $done    = 0;

                        foreach ($records as $r) {
                            if ($r->isDraft() && ! in_array($r->worstSeverity(), ['red', 'yellow'], true)) {
                                $done += (int) $service->confirm($r, auth()->id())['ok'];
                            }
                        }

                        Notification::make()->title("Підтверджено: {$done} з {$records->count()}")->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function reviewAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('review')
            ->label(fn (CourierShiftReport $r) => $r->isDraft() && static::isAdmin() ? 'Перевірити' : 'Деталі')
            ->icon('heroicon-o-magnifying-glass')
            ->color(fn (CourierShiftReport $r) => $r->isDraft() ? 'warning' : 'gray')
            ->modalHeading(fn (CourierShiftReport $r) => 'Звіт · '.$r->employee?->name.' · '
                .\Carbon\Carbon::parse($r->dateString())->format('d.m').' · '.($r->shift_slot === 'evening' ? 'вечір' : 'ранок'))
            ->modalSubmitActionLabel('Підтвердити')
            ->modalSubmitAction(fn ($action, CourierShiftReport $r) => $r->isDraft() && static::isAdmin() ? $action : false)
            ->fillForm(function (CourierShiftReport $r) {
                $m = $r->mileageSummary();

                return [
                    'start_km'   => $m['start'],
                    'end_km'     => $m['end'],
                    'fuel_price' => $r->parsed['fuel_price'] ?? ($r->expected['fuel_price'] ?? null),
                    'cash'       => collect($r->parsed['cash'] ?? [])->mapWithKeys(fn ($l) => [(string) $l['order_id'] => $l['received'] ?? 0])->all(),
                ];
            })
            ->form(fn (CourierShiftReport $r) => [
                Forms\Components\Placeholder::make('summary')
                    ->label('')
                    ->content(new HtmlString(static::summaryHtml($r))),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('start_km')->label('Старт, '.($r->mileageSummary()['unit'] === 'mi' ? 'mi' : 'км'))->numeric()->disabled(! $r->isDraft()),
                    Forms\Components\TextInput::make('end_km')->label('Фініш, '.($r->mileageSummary()['unit'] === 'mi' ? 'mi' : 'км'))->numeric()->disabled(! $r->isDraft()),
                    Forms\Components\TextInput::make('fuel_price')->label('Пальне, грн/л')->numeric()->disabled(! $r->isDraft()),
                ]),
                Forms\Components\Fieldset::make('Готівка від клієнтів — отримав')
                    ->schema(collect($r->parsed['cash'] ?? [])->map(fn ($l) => Forms\Components\TextInput::make('cash.'.$l['order_id'])
                        ->label('#'.$l['order_id'].' '.($l['client'] ?? '').' · чекали '.number_format((float) ($l['expected'] ?? 0), 0, ',', ' '))
                        ->numeric()->suffix('₴')->disabled(! $r->isDraft()))->all())
                    ->visible(! empty($r->parsed['cash'])),
            ])
            ->action(function (CourierShiftReport $r, array $data) {
                $res = app(CourierReportService::class)->confirm($r, auth()->id(), $data);

                $n = Notification::make()->title($res['ok'] ? 'Звіт підтверджено, виплату дня перераховано' : ($res['note'] ?? 'Не вдалося'));
                $res['ok'] ? $n->success() : $n->danger();
                if ($res['ok'] && $res['note']) {
                    $n->body($res['note'])->warning();
                }
                $n->send();
            });
    }

    protected static function summaryHtml(CourierShiftReport $r): string
    {
        $m     = $r->mileageSummary();
        $e     = fn ($v) => e((string) $v);
        $rows  = [];
        $route = implode(', ', array_map(fn ($n) => '№'.$n, $r->expected['route_nums'] ?? [])) ?: '—';

        $rows[] = '<b>Маршрут:</b> '.$e($route).' · '.$m['stops'].' точок'
            .($m['plan'] > 0 ? ' · план '.(int) round($m['plan']).' км' : ' · плану з Мурашки немає');
        $rows[] = '<b>Пробіг:</b> '.($m['km'] ?? '—').' км'
            .($m['unit'] === 'mi' && $m['raw'] !== null ? ' ('.$m['raw'].' mi на одометрі)' : '')
            .($m['start'] !== null ? ' · старт '.$m['start'] : '').($m['end'] !== null ? ' · фініш '.$m['end'] : '');

        foreach ($r->anomalies ?? [] as $a) {
            $icon   = match ($a['severity'] ?? '') { 'red' => '🔴', 'yellow' => '🟡', default => 'ℹ️' };
            $rows[] = $icon.' '.$e($a['text'] ?? '');
        }

        if ($r->ai_comment) {
            $rows[] = '🤖 <b>ШІ:</b> '.$e($r->ai_comment);
        }

        if ($r->raw_text) {
            $rows[] = '<details><summary>Текст курʼєра</summary><pre style="white-space:pre-wrap;font-size:12px">'.$e($r->raw_text).'</pre></details>';
        }

        $photos = collect($r->photos ?? [])
            ->filter(fn ($p) => is_string($p) && ! str_starts_with($p, 'tg:'))
            ->map(fn ($p) => '<a href="'.$e(URL::temporarySignedRoute('ops.attachment', now()->addHour(), ['path' => $p])).'" target="_blank">'
                .'<img src="'.$e(URL::temporarySignedRoute('ops.attachment', now()->addHour(), ['path' => $p])).'" style="height:120px;border-radius:6px;display:inline-block;margin-right:6px"></a>');

        if ($photos->isNotEmpty()) {
            $rows[] = '<div style="margin-top:6px">'.$photos->implode('').'</div>';
        }

        if ($r->status === CourierShiftReport::STATUS_REJECTED && $r->reject_reason) {
            $rows[] = '❌ Відхилено: '.$e($r->reject_reason);
        }

        return '<div style="line-height:1.7">'.implode('<br>', $rows).'</div>';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourierShiftReports::route('/'),
        ];
    }
}
