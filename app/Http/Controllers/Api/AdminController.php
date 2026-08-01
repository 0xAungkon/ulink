<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Link;
use App\Models\LinkVisit;
use App\Models\PublicDomain;
use App\Support\LinkCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function login(): JsonResponse
    {
        return response()->json(['authenticated' => true]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $links = Link::query()
            ->withCount([
                'visits as total_hits',
                'visits as failed_hits' => fn ($query) => $query->where('successful', false),
            ])
            ->latest()
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 100));

        return response()->json([
            'stats' => [
                'total_links' => Link::count(),
                'active_links' => Link::where('is_active', true)->where('expires_at', '>', now())->count(),
                'disabled_links' => Link::where('is_active', false)->count(),
                'expired_links' => Link::where('expires_at', '<=', now())->count(),
                'total_hits' => LinkVisit::count(),
                'failed_hits' => LinkVisit::where('successful', false)->count(),
            ],
            'links' => $links->through(fn (Link $link) => [
                'id' => $link->id,
                'token_id' => $link->token_id,
                'public_url' => $link->publicUrl(),
                'destination_url' => $link->destination_url,
                'delivery_mode' => $link->delivery_mode,
                'is_active' => $link->is_active,
                'total_hits' => $link->total_hits,
                'failed_hits' => $link->failed_hits,
                'expire_at' => $link->expires_at->toIso8601String(),
                'created_at' => $link->created_at->toIso8601String(),
            ]),
            'domains' => PublicDomain::orderByDesc('is_default')->orderBy('label')->get(),
        ]);
    }

    public function destroy(Link $link): JsonResponse
    {
        $link->delete();

        return response()->json(['deleted' => true]);
    }

    public function showLink(Request $request, Link $link): JsonResponse
    {
        $filters = $request->validate([
            'method' => ['nullable', 'string', 'max:12'],
            'path' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(['success', 'failed'])],
            'sort' => ['nullable', Rule::in(['date', 'method', 'path', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $link->loadCount([
            'visits as total_hits',
            'visits as failed_hits' => fn ($query) => $query->where('successful', false),
        ]);

        $sortColumns = [
            'date' => 'created_at',
            'method' => 'request_method',
            'path' => 'request_path',
            'status' => 'successful',
        ];
        $visits = $link->visits()
            ->when($filters['method'] ?? null, fn ($query, $method) => $query->where('request_method', strtoupper($method)))
            ->when($filters['path'] ?? null, fn ($query, $path) => $query->where('request_path', 'like', '%'.$path.'%'))
            ->when(($filters['status'] ?? null) === 'success', fn ($query) => $query->where('successful', true))
            ->when(($filters['status'] ?? null) === 'failed', fn ($query) => $query->where('successful', false))
            ->orderBy($sortColumns[$filters['sort'] ?? 'date'], $filters['direction'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $visits->through(fn ($visit) => [
            'id' => $visit->id,
            'ip' => $visit->ip_address,
            'location' => array_filter([
                'country' => $visit->country,
                'region' => $visit->region,
                'city' => $visit->city,
            ]),
            'browser' => $visit->browser,
            'device' => $visit->device,
            'operating_system' => $visit->operating_system,
            'user_agent' => $visit->user_agent,
            'request_method' => $visit->request_method,
            'request_path' => $visit->request_path,
            'referrer' => $visit->referrer,
            'accept_language' => $visit->accept_language,
            'accept' => $visit->accept_header,
            'client_hints' => $visit->client_hints ?: [],
            'successful' => $visit->successful,
            'failure_reason' => $visit->failure_reason,
            'visited_at' => $visit->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'id' => $link->id,
            'token_id' => $link->token_id,
            'public_url' => $link->publicUrl(),
            'destination_url' => $link->destination_url,
            'delivery_mode' => $link->delivery_mode,
            'is_active' => $link->is_active,
            'hits' => ['total' => $link->total_hits, 'failed' => $link->failed_hits],
            'expire_at' => $link->expires_at->toIso8601String(),
            'expired' => $link->expires_at->isPast(),
            'created_at' => $link->created_at->toIso8601String(),
            'updated_at' => $link->updated_at->toIso8601String(),
            'users' => $visits->items(),
            'requests' => $visits,
            'destination_history' => $link->destinations()->latest('created_at')->latest('id')->get()->map(fn ($destination, $index) => [
                'id' => $destination->id,
                'url' => $destination->url,
                'created_at' => $destination->created_at?->toIso8601String(),
                'is_current' => $index === 0,
            ]),
        ]);
    }

    public function updateLink(Request $request, Link $link): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $link->update($data);

        return response()->json([
            'id' => $link->id,
            'is_active' => $link->is_active,
            'message' => $link->is_active ? 'Link enabled.' : 'Link disabled.',
        ]);
    }

    public function regenerateSecret(Link $link): JsonResponse
    {
        $secret = Str::random(48);
        $link->update(['secret_hash' => LinkCredentials::hash($secret)]);

        return response()->json([
            'token_id' => $link->token_id,
            'secret_key' => $secret,
        ]);
    }

    public function storeDomain(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'base_url' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $data['base_url'] = PublicDomain::normalize($data['base_url']);
        $this->validateBaseUrl($data['base_url']);

        if (PublicDomain::where('base_url', $data['base_url'])->exists()) {
            throw ValidationException::withMessages(['base_url' => 'This public domain is already configured.']);
        }

        $domain = DB::transaction(function () use ($data) {
            $makeDefault = ($data['is_default'] ?? false) || ! PublicDomain::where('is_default', true)->exists();
            if ($makeDefault) {
                PublicDomain::query()->update(['is_default' => false]);
            }

            return PublicDomain::create([
                'label' => $data['label'] ?? null,
                'base_url' => $data['base_url'],
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $makeDefault,
            ]);
        });

        return response()->json($domain, 201);
    }

    public function updateDomain(Request $request, PublicDomain $domain): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'base_url' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['base_url'])) {
            $data['base_url'] = PublicDomain::normalize($data['base_url']);
            $this->validateBaseUrl($data['base_url']);
            if (PublicDomain::where('base_url', $data['base_url'])->where('id', '!=', $domain->id)->exists()) {
                throw ValidationException::withMessages(['base_url' => 'This public domain is already configured.']);
            }
        }

        DB::transaction(function () use ($domain, $data) {
            if ($data['is_default'] ?? false) {
                PublicDomain::where('id', '!=', $domain->id)->update(['is_default' => false]);
                $data['is_active'] = true;
            }
            $domain->update($data);
        });

        return response()->json($domain->fresh());
    }

    public function destroyDomain(PublicDomain $domain): JsonResponse
    {
        $wasDefault = $domain->is_default;
        $domain->delete();
        if ($wasDefault) {
            PublicDomain::where('is_active', true)->oldest()->first()?->update(['is_default' => true]);
        }

        return response()->json(['deleted' => true]);
    }

    private function validateBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);
        if (! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment']) || (($parts['path'] ?? '/') !== '/')) {
            throw ValidationException::withMessages([
                'base_url' => 'Enter only a domain origin, such as https://go.example.com.',
            ]);
        }
    }
}
