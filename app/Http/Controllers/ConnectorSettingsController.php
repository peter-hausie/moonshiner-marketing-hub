<?php

namespace App\Http\Controllers;

use App\Services\Connector\SaveConnection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ConnectorSettingsController extends Controller
{
    public function __invoke(Request $request, SaveConnection $settings)
    {
        abort_unless($request->user()?->canManageAccounts(), 403);
        try {
            $settings->save($request->user(), $request->only(['revision', 'enabled', 'api_key', 'writer_agent_id', 'research_agent_id']));
        } catch (ValidationException $error) {
            // Do not use withInput: API keys must never enter the flashed session or page state.
            return back()->withErrors($error->validator)->with('connector_notice', 'Eingaben prüfen. Schlüssel wurde nicht übernommen.');
        }

        return redirect('/admin/provider-connector')->with('connector_notice', 'Gespeichert. Neue Aufträge verwenden diese Verbindung.');
    }
}
