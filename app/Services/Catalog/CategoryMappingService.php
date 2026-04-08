<?php

namespace App\Services\Catalog;

use App\Models\CatalogCategory;
use App\Models\CategoryMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class CategoryMappingService
{
    public function __construct(
        private MappingFeedbackService $feedbackService,
    ) {}

    /**
     * @param  ?int  $minConfidence  Applied when > 0 (query param was truthy).
     * @param  ?string  $status  "unmapped" → empty page (use suggestions API); "mapped" or null → list rows.
     */
    public function listPaginated(int $perPage = 50, ?int $minConfidence = null, ?string $status = null): LengthAwarePaginator
    {
        if ($status === 'unmapped') {
            $page = max(1, (int) request()->get('page', 1));

            return new ConcretePaginator(
                new Collection,
                0,
                $perPage,
                $page,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        }

        $query = CategoryMapping::with('donorCategory', 'catalogCategory')->orderBy('id');

        if ($minConfidence !== null && $minConfidence > 0) {
            $query->where('confidence', '>=', $minConfidence);
        }

        return $query->paginate($perPage);
    }

    public function create(array $data): CategoryMapping
    {
        $mapping = CategoryMapping::create($data);
        event(new \App\Events\CatalogMappingCreated($mapping));

        return $mapping;
    }

    public function delete(CategoryMapping $mapping): void
    {
        $mapping->delete();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function deleteMapping(int $id): void
    {
        $mapping = CategoryMapping::query()->find($id);
        if ($mapping === null) {
            throw (new ModelNotFoundException)->setModel(CategoryMapping::class, [$id]);
        }
        $this->delete($mapping);
    }

    /**
     * CRM manual upsert: update existing (remap) or create new mapping.
     *
     * @return array{mapping: CategoryMapping, created: bool}
     */
    public function upsertManualMapping(
        int $donorCategoryId,
        int $catalogCategoryId,
        int $confidence,
        bool $isManualOnCreate = false,
    ): array {
        $existing = CategoryMapping::query()->where('donor_category_id', $donorCategoryId)->first();

        if ($existing) {
            $existing->update([
                'catalog_category_id' => $catalogCategoryId,
                'confidence' => $confidence,
                'is_manual' => true,
            ]);

            $mapping = $existing->fresh()->load(['donorCategory', 'catalogCategory']);
            $this->logManualOverrideSafe($mapping);

            return ['mapping' => $mapping, 'created' => false];
        }

        $mapping = $this->create([
            'donor_category_id' => $donorCategoryId,
            'catalog_category_id' => $catalogCategoryId,
            'confidence' => $confidence,
            'is_manual' => $isManualOnCreate,
        ]);
        $mapping->load(['donorCategory', 'catalogCategory']);

        if ($isManualOnCreate) {
            $this->logManualOverrideSafe($mapping);
        }

        return ['mapping' => $mapping, 'created' => true];
    }

    private function logManualOverrideSafe(CategoryMapping $mapping): void
    {
        try {
            $this->feedbackService->logManualOverride(
                (int) $mapping->donor_category_id,
                (int) $mapping->catalog_category_id,
                (int) ($mapping->confidence ?? 100)
            );
        } catch (Throwable $e) {
            Log::warning('auto_mapping manual_override log failed', [
                'donor_category_id' => $mapping->donor_category_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function resolveCatalogCategoryId(int $donorCategoryId): ?int
    {
        $mapping = CategoryMapping::where('donor_category_id', $donorCategoryId)->first();

        return $mapping?->catalog_category_id;
    }

    public function resolveCatalogCategory(int $donorCategoryId): ?CatalogCategory
    {
        $mapping = CategoryMapping::with('catalogCategory')
            ->where('donor_category_id', $donorCategoryId)
            ->first();

        return $mapping?->catalogCategory;
    }

    /**
     * Auto-mapping pipeline: insert or update non-manual mapping only.
     */
    public function applyAutomaticMapping(
        int $donorCategoryId,
        int $catalogCategoryId,
        int $confidence,
        ?CategoryMapping $existing = null,
    ): CategoryMapping {
        $existing ??= CategoryMapping::query()->where('donor_category_id', $donorCategoryId)->first();

        $payload = [
            'donor_category_id' => $donorCategoryId,
            'catalog_category_id' => $catalogCategoryId,
            'confidence' => $confidence,
            'is_manual' => false,
        ];

        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }

        return $this->create($payload);
    }
}
