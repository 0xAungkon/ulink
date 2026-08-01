<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Link;
use App\Models\PublicDomain;
use App\Support\LinkCredentials;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LinkController extends Controller
{
    public function domains(): JsonResponse
    {
        $domains = PublicDomain::where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->get(['id', 'label', 'base_url', 'is_default']);

        if ($domains->isEmpty()) {
            return response()->json([[
                'id' => null,
                'label' => 'Main domain',
                'base_url' => rtrim((string) config('app.url'), '/'),
                'is_default' => true,
            ]]);
        }

        return response()->json($domains);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url:http,https', 'max:4096'],
            'expire_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'type' => ['nullable', 'in:anonymous,anoymouse,redirect,proxy'],
            'domain_id' => ['nullable', 'integer', 'exists:public_domains,id'],
        ]);

        $expiresAt = Carbon::parse($data['expire_at'] ?? $data['expires_at'] ?? now()->addYear());
        if ($expiresAt->lte(now()) || $expiresAt->gt(now()->addYear()->addMinute())) {
            throw ValidationException::withMessages([
                'expire_at' => 'Expiration must be in the future and no more than one year from now.',
            ]);
        }

        $domain = isset($data['domain_id'])
            ? PublicDomain::where('is_active', true)->find($data['domain_id'])
            : PublicDomain::where('is_active', true)->orderByDesc('is_default')->first();

        if (isset($data['domain_id']) && ! $domain) {
            throw ValidationException::withMessages(['domain_id' => 'The selected public domain is not active.']);
        }

        $publicBaseUrl = $domain?->base_url ?: rtrim((string) config('app.url'), '/');
        $secret = Str::random(48);
        $link = Link::create([
            'token_id' => Str::lower(Str::random(24)),
            'secret_hash' => LinkCredentials::hash($secret),
            'slug' => Str::lower(Str::random(10)),
            'destination_url' => $data['url'],
            'public_base_url' => $publicBaseUrl,
            'delivery_mode' => ($data['type'] ?? 'redirect') === 'proxy' ? 'proxy' : 'redirect',
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'token_id' => $link->token_id,
            'secret_key' => $secret,
            'expire_at' => $link->expires_at->toIso8601String(),
            'url' => $link->publicUrl(),
            'type' => $link->delivery_mode,
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token_id' => ['required', 'string'],
            'secret_key' => ['nullable', 'string'],
            'secreat_key' => ['nullable', 'string'],
            'url' => ['required', 'url:http,https', 'max:4096'],
        ]);

        $link = Link::where('token_id', $data['token_id'])->firstOrFail();
        $secret = (string) ($data['secret_key'] ?? $data['secreat_key'] ?? '');
        abort_unless(LinkCredentials::valid($link, $secret), 401, 'Invalid link credentials.');
        abort_if($link->expires_at->isPast(), 410, 'This link has expired.');

        $link->update(['destination_url' => $data['url']]);

        return response()->json([
            'url' => $link->publicUrl(),
            'destination_url' => $link->destination_url,
            'type' => $link->delivery_mode,
            'expire_at' => $link->expires_at->toIso8601String(),
        ]);
    }

    public function show(Request $request, ?string $token_id = null): JsonResponse
    {
        [$tokenId, $secret] = LinkCredentials::fromRequest($request);
        $link = Link::where('token_id', $tokenId)->firstOrFail();
        abort_unless(LinkCredentials::valid($link, $secret), 401, 'Invalid link credentials.');

        return response()->json($this->details($link));
    }

    private function details(Link $link): array
    {
        $link->loadCount([
            'visits as total_hits',
            'visits as failed_hits' => fn ($query) => $query->where('successful', false),
        ]);

        return [
            'token_id' => $link->token_id,
            'public_url' => $link->publicUrl(),
            'destination_url' => $link->destination_url,
            'hits' => ['total' => $link->total_hits, 'failed' => $link->failed_hits],
            'users' => $link->visits()->latest('created_at')->limit(100)->get()->map(fn ($visit) => [
                'ip' => $visit->ip_address,
                'location' => array_filter([
                    'country' => $visit->country,
                    'region' => $visit->region,
                    'city' => $visit->city,
                ]),
                'browser' => $visit->browser,
                'device' => $visit->device,
                'successful' => $visit->successful,
                'visited_at' => $visit->created_at?->toIso8601String(),
            ]),
            'expire_at' => $link->expires_at->toIso8601String(),
            'expired' => $link->expires_at->isPast(),
        ];
    }
}
