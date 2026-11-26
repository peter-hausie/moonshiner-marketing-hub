<?php

namespace App\Filament\Pages;

use App\Models\AccountInvitation;
use App\Models\User;
use App\Services\Accounts\ManageAccounts;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Accounts extends Page
{
    protected static ?string $title = 'Accounts & Rechte';

    protected static ?string $navigationLabel = 'Accounts & Rechte';

    protected string $view = 'filament.pages.accounts';

    public string $name = '';

    public string $email = '';

    public string $access = 'contributor';

    public static function canAccess(): bool
    {
        return auth()->user()?->canManageAccounts() ?? false;
    }

    public function invite(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email', 'access' => 'required|in:admin,curator,contributor']);
        $user = app(ManageAccounts::class)->invite(auth()->user(), $this->name, $this->email, $this->access);
        $this->name = '';
        $this->email = '';
        $this->access = 'contributor';
        $this->notifyDelivery($user->id);
    }

    public function resend(int $id): void
    {
        app(ManageAccounts::class)->sendInvitation(auth()->user(), $id);
        $this->notifyDelivery($id);
    }

    private function notifyDelivery(int $id): void
    {
        $sent = AccountInvitation::where('user_id', $id)->latest('id')->value('delivery_status') === 'sent';
        $notification = Notification::make()->title($sent ? 'Einladung per E-Mail versendet' : 'Account gespeichert – Einladung nicht versendet');
        if ($sent) {
            $notification->success();
        } else {
            $notification->warning()->body('Mailversand nicht verfügbar oder fehlgeschlagen. Konfiguration prüfen, danach erneut einladen.');
        }
        $notification->send();
    }

    public function editAccessAction(): Action
    {
        return Action::make('editAccess')->label('Rechte bearbeiten')->fillForm(function (array $arguments): array {
            abort_unless(static::canAccess(), 403);
            $user = User::findOrFail($arguments['id']);

            return ['access' => $user->is_admin ? 'admin' : $user->role->value, 'active' => $user->active];
        })->schema([
            Select::make('access')->label('Rolle')->options(['admin' => 'Administrator', 'curator' => 'Redaktionsleitung', 'contributor' => 'Autor'])->required(),
            Toggle::make('active')->label('Account aktiv')->helperText('Deaktivierung sperrt auch bestehende Sitzungen. Neue Accounts aktivieren sich über die Einladung.'),
        ])->action(function (array $data, array $arguments): void {
            app(ManageAccounts::class)->update(auth()->user(), $arguments['id'], $data['access'], (bool) $data['active']);
            Notification::make()->success()->title('Zugriffsrechte gespeichert')->send();
        });
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return ['users' => User::orderBy('name')->get(), 'invitations' => AccountInvitation::orderBy('id')->get()->keyBy('user_id')];
    }
}
