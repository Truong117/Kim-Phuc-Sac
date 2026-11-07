<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\MailConnection;
use App\Models\MailMessage;
use App\Models\OrganizationMembership;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailConfiguration;
use App\Services\Mail\MailConnectionService;
use App\Services\Mail\MailOutbox;
use App\Services\Mail\MailPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MailController extends Controller
{
    public function __construct(private readonly MailAccess $access, private readonly MailConfiguration $configuration, private readonly MailConnectionService $connections, private readonly MailOutbox $outbox) {}

    public function capabilities(Request $request): JsonResponse
    {
        return response()->json(['can_view' => $this->access->membership($request->user()) !== null, 'can_configure' => $this->access->membership($request->user(), true) !== null]);
    }

    public function show(Request $request): JsonResponse
    {
        $member = $this->access->require($request->user());
        $enabled = (bool) config('kps_mail.enabled');
        $configured = $this->configuration->ready();
        $schema = $enabled && $this->schemaReady();
        $connection = $schema ? MailConnection::query()->where('organization_id', $member->organization_id)->first() : null;
        $connectionStatus = $connection?->status ?? 'disconnected';
        if ($connectionStatus === 'connected' && (! $configured || ! hash_equals($this->configuration->fingerprint(), (string) $connection->configuration_hash))) {
            $connectionStatus = 'reconnect_required';
        }
        $history = $schema ? MailMessage::query()->where('organization_id', $member->organization_id)->latest()->limit(20)->get()->map(fn ($message) => $this->messagePayload($message)) : [];

        return response()->json(['data' => [
            'enabled' => $enabled, 'configured' => $configured, 'schema_ready' => $schema,
            'send_enabled' => (bool) config('kps_mail.send_enabled'), 'expected_email' => config('kps_mail.gmail.expected_email'),
            'status' => ! $enabled ? 'disabled' : (! $schema ? 'setup_required' : $connectionStatus),
            'email' => $connection?->email, 'connected_at' => $connection?->connected_at?->toIso8601String(),
            'from_name' => $this->configuration->fromName(),
            'history' => $history,
        ], 'actions' => ['can_configure' => $this->access->membership($request->user(), true) !== null]]);
    }

    public function recipients(Request $request): JsonResponse
    {
        $member = $this->access->require($request->user());

        return response()->json(['data' => OrganizationMembership::query()->where('organization_id', $member->organization_id)->where('is_active', true)->where('is_default', true)
            ->with(['user:id,name,email', 'department:id,name'])->get()->filter(fn ($member) => $member->user !== null)->unique('user_id')->sortBy('user.name')->values()->map(fn ($member) => [
                'id' => (string) $member->user_id, 'name' => $member->user->name, 'email' => $member->user->email, 'department' => $member->department?->name,
                'test_allowed' => filter_var($member->user->email, FILTER_VALIDATE_EMAIL) !== false && $this->outbox->allowedTestEmail($member->user->email),
                'send_allowed' => filter_var($member->user->email, FILTER_VALIDATE_EMAIL) !== false,
            ])]);
    }

    public function connect(Request $request): JsonResponse
    {
        $this->access->require($request->user(), true);
        $this->requireAvailable();

        return response()->json(['authorization_url' => $this->connections->start($request->user())])->header('Cache-Control', 'no-store');
    }

    public function callback(Request $request): Response
    {
        $result = 'failed';
        try {
            $this->requireAvailable();
            $state = $request->query('state');
            $code = $request->query('code');
            abort_unless(is_string($state), 400);
            $this->connections->complete($state, is_string($code) ? $code : null);
            $result = 'connected';
        } catch (Throwable) {
            // Only a fixed result is returned. Never expose/log authorization
            // codes, provider errors, or exception traces to the browser.
        }
        if (! $this->configuration->ready()) {
            return response()->json(['code' => 'MAIL_OAUTH_FAILED'], 400, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        }

        return redirect()->away(rtrim((string) config('kps_mail.frontend_url'), '/').'/integrations?mail_oauth='.$result)
            ->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->access->require($request->user(), true);
        abort_unless($this->schemaReady(), 503);
        $this->connections->disconnect($request->user());

        return response()->json(null, 204);
    }

    public function sendTest(Request $request): JsonResponse
    {
        $this->access->require($request->user());
        $this->requireAvailable();
        $input = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'min:1'], 'request_key' => ['required', 'uuid'],
            'to' => ['prohibited'], 'email' => ['prohibited'], 'cc' => ['prohibited'], 'bcc' => ['prohibited'],
            'organization_id' => ['prohibited'], 'subject' => ['prohibited'], 'body' => ['prohibited'], 'provider' => ['prohibited'],
        ]);
        $message = $this->outbox->queueTest($request->user(), (int) $input['recipient_user_id'], $input['request_key']);
        // One bounded send works on Render Free without an always-on worker.
        // The same outbox processor can later be called by CLI/queue/scheduler.
        $this->outbox->process($message->id);

        return response()->json(['data' => $this->messagePayload($message->fresh())], 202);
    }

    private function messagePayload(MailMessage $message): array
    {
        return ['id' => $message->id, 'recipient_user_id' => (string) $message->recipient_user_id, 'created_by' => (string) $message->created_by,
            'recipient_email' => $message->payload['to'], 'subject' => $message->payload['subject'], 'kind' => $message->kind, 'status' => $message->status, 'error_code' => $message->error_code,
            'created_at' => $message->created_at->toIso8601String(), 'accepted_at' => $message->accepted_at?->toIso8601String()];
    }

    public function queueTests(Request $request): JsonResponse
    {
        $this->access->require($request->user());
        $this->requireAvailable();
        $input = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:50'],
            'recipients.*' => ['required', 'array:recipient_user_id,request_key'],
            'recipients.*.recipient_user_id' => ['required', 'integer', 'min:1', 'distinct'],
            'recipients.*.request_key' => ['required', 'uuid', 'distinct'],
            'to' => ['prohibited'], 'email' => ['prohibited'], 'cc' => ['prohibited'], 'bcc' => ['prohibited'],
            'organization_id' => ['prohibited'], 'subject' => ['prohibited'], 'body' => ['prohibited'], 'provider' => ['prohibited'],
        ]);
        $messages = $this->outbox->queueTests($request->user(), $input['recipients']);

        return response()->json(['data' => array_map(fn (MailMessage $message) => $this->messagePayload($message), $messages)], 202);
    }

    public function processTest(Request $request, string $id): JsonResponse
    {
        return $this->processOwnedMessage($request, $id, 'test');
    }

    public function previewMessage(Request $request): JsonResponse
    {
        $this->access->require($request->user());
        $this->requireAvailable();
        $input = $request->validate($this->contentRules());

        return response()->json(['data' => ['html' => MailPresentation::render($input['body']), 'layout' => MailPresentation::LAYOUT]])
            ->header('Cache-Control', 'no-store');
    }

    private function contentRules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'body' => ['required', 'string', 'max:10000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
            'to' => ['prohibited'], 'email' => ['prohibited'], 'cc' => ['prohibited'], 'bcc' => ['prohibited'],
            'organization_id' => ['prohibited'], 'provider' => ['prohibited'], 'from' => ['prohibited'],
            'html' => ['prohibited'], 'attachments' => ['prohibited'], 'kind' => ['prohibited'],
            'layout' => ['sometimes', 'in:'.MailPresentation::LAYOUT],
        ];
    }

    public function queueMessages(Request $request): JsonResponse
    {
        $this->access->require($request->user());
        $this->requireAvailable();
        $input = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:50'],
            'recipients.*' => ['required', 'array:recipient_user_id,request_key'],
            'recipients.*.recipient_user_id' => ['required', 'integer', 'min:1', 'distinct'],
            'recipients.*.request_key' => ['required', 'uuid', 'distinct'],
            ...$this->contentRules(),
        ]);
        $messages = $this->outbox->queueMessages($request->user(), $input['recipients'], $input['subject'], $input['body']);

        return response()->json(['data' => array_map(fn (MailMessage $message) => $this->messagePayload($message), $messages)], 202);
    }

    public function processMessage(Request $request, string $id): JsonResponse
    {
        return $this->processOwnedMessage($request, $id, 'internal');
    }

    private function processOwnedMessage(Request $request, string $id, string $kind): JsonResponse
    {
        $member = $this->access->require($request->user());
        $this->requireAvailable();
        $message = MailMessage::query()->whereKey($id)->where('organization_id', $member->organization_id)
            ->where('created_by', $request->user()->id)->where('kind', $kind)->firstOrFail();
        MailMessage::query()->whereKey($message->id)->where('status', 'sending')->where('started_at', '<', now()->subMinutes(5))
            ->update(['status' => 'unknown', 'error_code' => 'PROCESS_INTERRUPTED']);
        $this->outbox->process($message->id);

        return response()->json(['data' => $this->messagePayload($message->fresh())], 202);
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('mail_connections') && Schema::hasTable('mail_oauth_attempts') && Schema::hasTable('mail_messages');
    }

    private function requireAvailable(): void
    {
        $this->configuration->requireReady();
        abort_unless($this->schemaReady(), 503, 'KPS_MAIL_SCHEMA_REQUIRED');
    }
}
