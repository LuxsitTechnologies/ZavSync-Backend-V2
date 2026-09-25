<?php

namespace App\Http\Controllers\Api\V1\Outreach;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Outreach\EmailTemplateRequest;
use App\Http\Resources\EmailTemplateResource;
use App\Models\CrmContact;
use App\Models\EmailTemplate;
use App\Services\AuditService;
use App\Services\Outreach\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmailTemplateController extends Controller
{
    use AuthorizesOutreachRequests;

    public function __construct(private readonly TemplateRenderer $renderer, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'outreach.view');

        return EmailTemplateResource::collection(EmailTemplate::query()->where('company_id', $this->companyId($request))->orderBy('name')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(EmailTemplateRequest $request): EmailTemplateResource
    {
        $data = $request->validated();
        $data['allowed_variables'] = $this->renderer->variables($data['subject'], $data['body_text'], $data['body_html'] ?? '');
        $data['archived_at'] = ($data['is_active'] ?? true) ? null : now();
        $template = EmailTemplate::query()->create([...$data, 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'email_template_created', 'outreach', $template, null, $template->toArray());

        return new EmailTemplateResource($template);
    }

    public function update(EmailTemplateRequest $request, string $template): EmailTemplateResource
    {
        $model = $this->template($request, $template);
        $data = $request->validated();
        $subject = $data['subject'] ?? $model->subject;
        $text = $data['body_text'] ?? $model->body_text;
        $html = array_key_exists('body_html', $data) ? ($data['body_html'] ?? '') : ($model->body_html ?? '');
        $data['allowed_variables'] = $this->renderer->variables($subject, $text, $html);
        if (array_key_exists('is_active', $data)) {
            $data['archived_at'] = $data['is_active'] ? null : now();
        }
        $old = $model->toArray();
        $model->update([...$data, 'updated_by' => $request->user()->id]);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'email_template_updated', 'outreach', $model, $old, $model->toArray());

        return new EmailTemplateResource($model->fresh());
    }

    public function preview(Request $request, string $template): JsonResponse
    {
        $this->authorizePermission($request, 'outreach.view');
        $data = $request->validate(['contact_id' => ['required', 'uuid']]);
        $model = $this->template($request, $template);
        $contact = CrmContact::query()->with('account')->where('company_id', $this->companyId($request))->findOrFail($data['contact_id']);
        $values = ['contact.first_name' => $contact->first_name, 'contact.last_name' => $contact->last_name, 'contact.email' => $contact->email, 'account.name' => $contact->account?->name, 'lead.name' => null, 'deal.name' => null, 'sender.name' => $request->user()->name, 'company.name' => $contact->company()->value('name')];

        return response()->json(['subject' => $this->renderer->render($model->subject, $values), 'body_text' => $this->renderer->render($model->body_text, $values), 'body_html' => $model->body_html ? $this->renderer->sanitizeHtml($this->renderer->render($model->body_html, $values, true)) : null]);
    }

    private function template(Request $request, string $id): EmailTemplate
    {
        return EmailTemplate::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }
}
