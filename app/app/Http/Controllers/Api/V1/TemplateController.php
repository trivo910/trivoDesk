<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\App\TemplateController as AppTemplateController;
use App\Http\Requests\Api\V1\Template\StoreTemplateRequest;
use App\Http\Requests\Api\V1\Template\UpdateTemplateRequest;
use App\Http\Resources\Api\V1\TemplateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Templates — manage the workspace's WhatsApp message templates.
 *
 * Reuses the tested mobile-app pipeline (App\Http\Controllers\Api\App\
 * TemplateController → WaTemplate, workspace-scoped) and re-wraps every
 * result in the public { data } / { error } envelope.
 */
class TemplateController extends V1Controller
{
    /** GET /api/v1/templates — list the workspace's templates. */
    public function index(Request $request): JsonResponse
    {
        $internal = Request::create('/api/app/get-templates', 'GET');
        $internal->setUserResolver(fn () => $request->user());

        $payload = app(AppTemplateController::class)->index($internal)->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return $this->fail('list_failed', $payload['message'] ?? 'Templates could not be listed.', 422);
        }

        $items = collect($payload['templates'] ?? [])
            ->map(fn ($t) => (new TemplateResource($t))->resolve())
            ->values();

        return $this->ok($items, ['count' => $items->count()]);
    }

    /** GET /api/v1/templates/categories — the Meta category list. */
    public function categories(): JsonResponse
    {
        $payload = app(AppTemplateController::class)->categories()->getData(true);

        return $this->ok($payload['categories'] ?? []);
    }

    /** POST /api/v1/templates — create a template. */
    public function store(StoreTemplateRequest $request): JsonResponse
    {
        $internal = Request::create('/api/app/templates-store', 'POST', $this->forwardFields($request));
        $internal->setUserResolver(fn () => $request->user());

        $payload = app(AppTemplateController::class)->store($internal)->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return $this->fail(
                'create_failed',
                $payload['message'] ?? 'Template could not be created.',
                422,
                (array) ($payload['errors'] ?? [])
            );
        }

        return $this->created((new TemplateResource($payload['data'] ?? []))->resolve());
    }

    /** GET /api/v1/templates/{id} — single template detail. */
    public function show(int $id): JsonResponse
    {
        $internal = Request::create('/api/app/templates/' . $id, 'GET');
        $internal->setUserResolver(fn () => request()->user());

        $payload = app(AppTemplateController::class)->show($internal, $id)->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return $this->fail('not_found', $payload['message'] ?? 'Template not found.', 404);
        }

        return $this->ok((new TemplateResource($payload['data'] ?? []))->resolve());
    }

    /** PUT /api/v1/templates/{id} — update a template. */
    public function update(UpdateTemplateRequest $request, int $id): JsonResponse
    {
        $internal = Request::create('/api/app/templates/' . $id, 'PUT', $this->forwardFields($request));
        $internal->setUserResolver(fn () => $request->user());

        $payload = app(AppTemplateController::class)->update($internal, $id)->getData(true);

        if (($payload['success'] ?? false) !== true) {
            $status = isset($payload['errors']) ? 422 : 404;
            return $this->fail(
                $status === 422 ? 'update_failed' : 'not_found',
                $payload['message'] ?? 'Template could not be updated.',
                $status,
                (array) ($payload['errors'] ?? [])
            );
        }

        return $this->ok((new TemplateResource($payload['data'] ?? []))->resolve());
    }

    /**
     * The field set forwarded to the mobile-app controller for store + update.
     * Includes the LOCATION header (flat latitude/longitude/location_name/
     * location_address) so location templates are reachable via the public API
     * — the App controller's collectLocation() reads exactly these keys.
     *
     * Note: file attachments (image/video/document) can't be uploaded through
     * this internal sub-request and are intentionally out of scope here; create
     * media templates in the dashboard.
     */
    private function forwardFields(Request $request): array
    {
        // Forward ONLY the keys the caller actually sent. Using input() for
        // every field would turn an omitted field into an explicit null, which
        // on a partial update overwrites the stored value (e.g. nulling a
        // NOT-NULL `language` column). only() omits absent keys so the App
        // controller's "keep existing value" defaults apply.
        return $request->only([
            'template_name', 'template_type', 'category', 'header',
            'template_body', 'footer', 'language', 'buttons', 'quick_replies',
            'carousel_data',
            // LOCATION header pin — folded into header_location by the App layer.
            'latitude', 'longitude', 'location_name', 'location_address',
        ]);
    }

    /** DELETE /api/v1/templates/{id} — delete a template. */
    public function destroy(int $id): JsonResponse
    {
        $internal = Request::create('/api/app/templates/' . $id, 'DELETE');
        $internal->setUserResolver(fn () => request()->user());

        $payload = app(AppTemplateController::class)->destroy($internal, $id)->getData(true);

        if (($payload['success'] ?? false) !== true) {
            return $this->fail('not_found', $payload['message'] ?? 'Template not found.', 404);
        }

        return $this->ok(['deleted' => true, 'message' => $payload['message'] ?? 'Template deleted successfully']);
    }
}
