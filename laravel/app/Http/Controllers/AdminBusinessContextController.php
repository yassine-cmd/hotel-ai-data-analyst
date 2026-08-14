<?php

namespace App\Http\Controllers;

use App\Models\BusinessContext;
use App\Models\SchemaMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminBusinessContextController extends Controller
{
    private function rules(?int $id = null): array
    {
        return [
            'title' => [
                'required', 'string', 'max:'.config('business_context.title_max'),
                Rule::unique('business_context', 'title')->ignore($id),
            ],
            'content' => [
                'required', 'string', 'max:'.config('business_context.content_max'),
                function (string $attribute, mixed $value, \Closure $fail) use ($id) {
                    // Total across all active unscoped entries must fit the
                    // renderer budget (18 = "[BUSINESS CONTEXT]" + newline).
                    // Scoped entries are excluded: they ride into per-table DDL.
                    $totalMax = (int) config('business_context.total_max');
                    $used = 18;
                    if (!request('scope_table')) {
                        $used += self::renderLength(request('title'), $value);
                    }
                    foreach (self::activeUnscopedEntries($id) as $e) {
                        $used += self::renderLength($e->title, $e->content);
                    }
                    if ($used > $totalMax) {
                        $fail(sprintf(
                            'Total business context exceeds %d characters (%d/%d used) — reduce content or deactivate entries.',
                            $totalMax,
                            $used,
                            $totalMax
                        ));
                    }
                },
            ],
            'scope_table' => [
                'nullable', 'string', 'max:191',
                Rule::exists('schema_metadata', 'table_name')->where(function ($query) {
                    $query->where('metadata_type', 'table')->where('is_archived', false);
                }),
            ],
            'scope_column' => [
                'nullable', 'string', 'max:191',
                'required_with:scope_table',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (empty($value)) {
                        return;
                    }
                    $table = request('scope_table');
                    if (empty($table)) {
                        $fail('The scope column requires a scope table.');
                        return;
                    }
                    $exists = SchemaMetadata::query()
                        ->where('metadata_type', 'column')
                        ->where('table_name', $table)
                        ->where('column_name', $value)
                        ->where('is_archived', false)
                        ->exists();
                    if (!$exists) {
                        $fail('The selected scope column does not exist in the chosen table.');
                    }
                },
            ],
            'is_active' => 'boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = BusinessContext::query();

        if ($request->filled('scope')) {
            $query->where('scope_table', $request->scope);
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return response()->json($query->orderBy('title')->get());
    }

    public function config(): JsonResponse
    {
        return response()->json([
            'title_max' => (int) config('business_context.title_max'),
            'content_max' => (int) config('business_context.content_max'),
            'total_max' => (int) config('business_context.total_max'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $entry = BusinessContext::create($validated);

        return response()->json($entry, 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(BusinessContext::findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $entry = BusinessContext::findOrFail($id);

        $validated = $request->validate($this->rules($id));

        $entry->update($validated);

        return response()->json($entry);
    }

    public function destroy(int $id): JsonResponse
    {
        BusinessContext::findOrFail($id)->delete();

        return response()->json(['status' => 'ok']);
    }

    private static function renderLength(?string $title, ?string $content): int
    {
        $t = trim((string) $title);
        $c = trim((string) $content);
        if ($t === '' || $c === '') {
            return 0;
        }

        // Mirrors agent/core.py::_format_business_context: "- {title}: {content}" + newline.
        return mb_strlen("- {$t}: {$c}") + 1;
    }

    private static function activeUnscopedEntries(?int $excludeId)
    {
        return BusinessContext::query()
            ->where('is_active', true)
            ->whereNull('scope_table')
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get(['title', 'content']);
    }
}
