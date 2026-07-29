<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\EducationProviderRequest;
use App\Models\AcademicYearConfiguration;
use App\Models\EducationProvider;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EducationProviderController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('providers.view');

        return Inertia::render('Academic/Providers/Index', [
            'providers' => EducationProvider::query()->orderByRaw('tenant_id is not null')->orderBy('name')->get()
                ->map(fn ($provider) => [...$provider->toArray(), 'is_shared' => $provider->isShared()]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('providers.manage');

        return Inertia::render('Academic/Providers/Form');
    }

    public function store(EducationProviderRequest $request, AuditService $audit): RedirectResponse
    {
        $provider = EducationProvider::create($request->validated());
        $audit->record('provider.created', $provider, [], $provider->toArray());

        return redirect()->route('academic.providers.index')->with('success', 'Provider created.');
    }

    public function edit(EducationProvider $provider): Response
    {
        Gate::authorize('update', $provider);

        return Inertia::render('Academic/Providers/Form', ['provider' => $provider]);
    }

    public function update(EducationProviderRequest $request, EducationProvider $provider, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $isHistorical = AcademicYearConfiguration::query()
            ->where('education_provider_id', $provider->id)
            ->whereIn('status', ['active', 'closed', 'archived'])
            ->exists();

        if ($isHistorical && collect($data)->except('status')->some(
            fn ($value, $key) => (string) $provider->getRawOriginal($key) !== (string) ($value ?? ''),
        )) {
            throw ValidationException::withMessages([
                'name' => 'A provider used by an active or historical configuration may only change status.',
            ]);
        }

        $before = $provider->toArray();
        $provider->update($data);
        $audit->record('provider.updated', $provider, $before, $provider->fresh()->toArray());

        return redirect()->route('academic.providers.index')->with('success', 'Provider updated.');
    }
}
