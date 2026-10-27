<?php

namespace App\Filament\Resources\BankReconciliationResource\Pages;

use App\Filament\Resources\BankReconciliationResource;
use App\Services\Accounting\BankReconciler;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListBankReconciliation extends ListRecords
{
    protected static string $resource = BankReconciliationResource::class;

    public function getSubheading(): ?string
    {
        $left = BankReconciler::queue()->whereNull('reconcile_status')->count();

        return 'Банк з '.Carbon::parse(BankReconciler::from())->format('d.m.Y').' · нерозібрано: '.$left
            .' · виписки підтягуються щоночі, звірка — одразу після них';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('auto')
                ->label('Підібрати автоматично')
                ->icon('heroicon-o-sparkles')
                ->modalDescription('Привʼяже вже внесені в CRM оплати й витрати, перекази між своїми рахунками і запамʼятованих контрагентів. Накладні закриваються лише кнопкою «Прийняти».')
                ->action(function () {
                    $r = app(BankReconciler::class)->autoRun(auth()->id());
                    Notification::make()
                        ->title('Розібрано: '.($r['link'] + $r['internal'] + $r['rule']))
                        ->body("уже в CRM — {$r['link']}, між своїми — {$r['internal']}, за правилами — {$r['rule']}; лишилось — {$r['left']}")
                        ->success()->send();
                }),
        ];
    }
}
