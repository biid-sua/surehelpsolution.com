<?php

namespace App\Http\Controllers;

use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a support attachment: for the business's people who can see support, or SureHelp staff
 * who answer it. Anyone else gets "not found".
 */
class SupportAttachmentController extends Controller
{
    public function __invoke(Request $request, int $message): StreamedResponse
    {
        $m = SupportTicketMessage::withoutGlobalScopes()->with('organization')->findOrFail($message);
        $user = $request->user();
        $allowed = $user->isAdmin() ? $user->hasPermissionIn('support.manage') : $user->hasPermissionIn('support.view', $m->organization);
        abort_unless($allowed && $m->attachment_path && Storage::disk('local')->exists($m->attachment_path), 404);

        return Storage::disk('local')->download($m->attachment_path, (string) $m->attachment_name);
    }
}
