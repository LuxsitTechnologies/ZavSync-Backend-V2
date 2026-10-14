<?php

namespace App\Http\Controllers\Api\V1\Fbr;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\FbrCompanyConfiguration;
use App\Services\AuditService;
use App\Services\Platform\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\File;

class PortalSettingsController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly DocumentService $documents) {}

    public function show(Request $request): JsonResponse
    {
        $company = $this->authorize($request, 'portal.settings.view');
        $settings = $this->settings($company);

        return response()->json($this->response($settings));
    }

    public function update(Request $request): JsonResponse
    {
        $company = $this->authorize($request, 'portal.settings.manage');
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'footer' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'declaration' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'default_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'number_prefix_display' => ['sometimes', 'nullable', 'alpha_dash', 'max:20'],
            'show_seller_email' => ['sometimes', 'boolean'],
            'show_seller_phone' => ['sometimes', 'boolean'],
            'show_seller_address' => ['sometimes', 'boolean'],
        ]);
        $mapping = [
            'footer' => 'fbr_document_footer', 'declaration' => 'fbr_document_declaration',
            'default_notes' => 'fbr_default_notes', 'number_prefix_display' => 'fbr_number_prefix_display',
            'show_seller_email' => 'fbr_show_seller_email', 'show_seller_phone' => 'fbr_show_seller_phone',
            'show_seller_address' => 'fbr_show_seller_address',
        ];
        $settings = DB::transaction(function () use ($company, $request, $data, $mapping): CompanySetting {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $settings = $this->settings($company, true);
            $this->assertVersion($settings, $data['version']);
            $before = $this->presentation($settings);
            $change = [];
            foreach ($mapping as $input => $column) {
                if (array_key_exists($input, $data)) {
                    $change[$column] = $data[$input];
                }
            }
            $settings->update([...$change, 'portal_settings_version' => $settings->portal_settings_version + 1, 'updated_by' => $request->user()->id]);
            $this->audit->record($request, $request->user(), $company->id, 'portal_settings_updated', 'pakistan_fbr', $settings,
                $before, $this->presentation($settings));

            return $settings;
        });

        return response()->json($this->response($settings));
    }

    public function reset(Request $request): JsonResponse
    {
        $company = $this->authorize($request, 'portal.settings.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $settings = DB::transaction(function () use ($company, $request, $data): CompanySetting {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $settings = $this->settings($company, true);
            $this->assertVersion($settings, $data['version']);
            $before = $this->presentation($settings);
            $settings->update([
                'fbr_document_footer' => null, 'fbr_document_declaration' => null, 'fbr_default_notes' => null,
                'fbr_number_prefix_display' => null, 'fbr_show_seller_email' => true,
                'fbr_show_seller_phone' => true, 'fbr_show_seller_address' => true,
                'logo_path' => null, 'portal_settings_version' => $settings->portal_settings_version + 1,
                'updated_by' => $request->user()->id,
            ]);
            $this->audit->record($request, $request->user(), $company->id, 'portal_settings_reset', 'pakistan_fbr', $settings,
                $before, $this->presentation($settings));

            return $settings;
        });

        return response()->json($this->response($settings));
    }

    public function logo(Request $request): JsonResponse
    {
        $company = $this->authorize($request, 'portal.settings.manage');
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'logo' => ['required', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(2048), 'extensions:png,jpg,jpeg,webp'],
        ]);
        $document = null;
        try {
            $settings = DB::transaction(function () use ($company, $request, $data, &$document): CompanySetting {
                Company::query()->lockForUpdate()->findOrFail($company->id);
                $settings = $this->settings($company, true);
                $this->assertVersion($settings, $data['version']);
                $document = $this->documents->store($company->id, $request->user(), 'company', $company->id, 'logo', $request->file('logo'));
                $old = $settings->logo_path;
                $settings->update(['logo_path' => $document->id, 'portal_settings_version' => $settings->portal_settings_version + 1,
                    'updated_by' => $request->user()->id]);
                $this->audit->record($request, $request->user(), $company->id, 'portal_logo_replaced', 'pakistan_fbr', $settings,
                    ['logo_document_id' => $old], ['logo_document_id' => $document->id]);

                return $settings;
            });
        } catch (\Throwable $exception) {
            if ($document !== null) {
                $this->documents->delete($document);
            }
            throw $exception;
        }

        return response()->json($this->response($settings));
    }

    public function replaceCredential(Request $request): JsonResponse
    {
        $company = $this->authorizeCredential($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'credential' => ['required', 'string', 'min:8', 'max:4000']]);

        return $this->changeCredential($request, $company, $data['version'], $data['credential']);
    }

    public function removeCredential(Request $request): JsonResponse
    {
        $company = $this->authorizeCredential($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->changeCredential($request, $company, $data['version'], null);
    }

    private function changeCredential(Request $request, Company $company, int $version, ?string $credential): JsonResponse
    {
        $settings = DB::transaction(function () use ($request, $company, $version, $credential): CompanySetting {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $settings = $this->settings($company, true);
            $this->assertVersion($settings, $version);
            $configuration = FbrCompanyConfiguration::query()->where('company_id', $company->id)->lockForUpdate()->first();
            if ($configuration === null) {
                throw new PlatformException('FBR_CONFIGURATION_REQUIRED', 'Complete FBR seller configuration before managing its credential.', 409);
            }
            $previouslyConfigured = filled($configuration->credential);
            $configuration->update(['credential' => $credential, 'connection_state' => 'NOT_VERIFIED',
                'last_verified_at' => null, 'last_error' => null, 'updated_by' => $request->user()->id]);
            $settings->update(['portal_settings_version' => $settings->portal_settings_version + 1, 'updated_by' => $request->user()->id]);
            $this->audit->record($request, $request->user(), $company->id,
                $credential === null ? 'portal_fbr_credential_removed' : 'portal_fbr_credential_replaced',
                'pakistan_fbr', $configuration,
                ['configured' => $previouslyConfigured], ['configured' => $credential !== null]);

            return $settings;
        });

        return response()->json($this->response($settings));
    }

    private function authorize(Request $request, string $permission): Company
    {
        $companyId = (string) $request->attributes->get('company_id');
        $company = Company::query()->findOrFail($companyId);
        if ($company->created_by !== $request->user()->id
            && ! $request->user()->hasCompanyPermission($companyId, $permission)) {
            throw new PlatformException('PERMISSION_DENIED', 'You do not have permission to manage Portal Settings.', 403);
        }

        return $company;
    }

    private function authorizeCredential(Request $request): Company
    {
        $company = $this->authorize($request, 'portal.settings.manage');
        if (! $request->user()->hasCompanyPermission($company->id, 'fbr.configuration.manage')) {
            throw new PlatformException('PERMISSION_DENIED', 'FBR credential management requires the dedicated permission.', 403);
        }

        return $company;
    }

    private function settings(Company $company, bool $lock = false): CompanySetting
    {
        $query = CompanySetting::query()->where('company_id', $company->id);
        $settings = $lock ? $query->lockForUpdate()->first() : $query->first();

        return $settings ?? CompanySetting::query()->create([
            'company_id' => $company->id, 'legal_name' => $company->name,
            'timezone' => $company->timezone, 'base_currency' => $company->currency,
        ]);
    }

    private function assertVersion(CompanySetting $settings, int $version): void
    {
        if ($settings->portal_settings_version !== $version) {
            throw new PlatformException('PORTAL_SETTINGS_VERSION_STALE', 'Portal Settings changed; reload before saving.', 409);
        }
        if ($version >= PHP_INT_MAX) {
            throw new PlatformException('PORTAL_SETTINGS_VERSION_EXHAUSTED', 'Portal Settings version is exhausted.', 409);
        }
    }

    /** @return array<string, mixed> */
    private function presentation(CompanySetting $settings): array
    {
        return [
            'version' => $settings->portal_settings_version,
            'logo_document_id' => $settings->logo_path,
            'footer' => $settings->fbr_document_footer,
            'declaration' => $settings->fbr_document_declaration,
            'default_notes' => $settings->fbr_default_notes,
            'number_prefix_display' => $settings->fbr_number_prefix_display,
            'show_seller_email' => $settings->fbr_show_seller_email,
            'show_seller_phone' => $settings->fbr_show_seller_phone,
            'show_seller_address' => $settings->fbr_show_seller_address,
        ];
    }

    /** @return array<string, mixed> */
    private function response(CompanySetting $settings): array
    {
        $configuration = FbrCompanyConfiguration::query()->where('company_id', $settings->company_id)->first();

        return [
            'company_id' => $settings->company_id,
            'presentation' => $this->presentation($settings),
            'fbr_configuration' => [
                'configured' => $configuration !== null,
                'credential_configured' => $configuration !== null && filled($configuration->credential),
                'environment' => $configuration?->environment,
                'connection_state' => $configuration?->connection_state,
            ],
        ];
    }
}
