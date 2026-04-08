<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\DonorCategory;
use App\Models\CategoryMapping;
use App\Services\Catalog\CatalogCategoryService;
use App\Services\Catalog\CategoryMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCategoryTest extends TestCase
{
    use RefreshDatabase;

    private CatalogCategoryService $catalogService;
    private CategoryMappingService $mappingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogService = app(CatalogCategoryService::class);
        $this->mappingService = app(CategoryMappingService::class);
    }

    public function test_category_creation(): void
    {
        $category = $this->catalogService->create([
            'name' => 'Shoes',
            'slug' => 'shoes',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('catalog_categories', ['slug' => 'shoes', 'name' => 'Shoes']);
        $this->assertSame(1, $category->sort_order);
        $this->assertTrue($category->is_active);
    }

    public function test_category_tree_retrieval(): void
    {
        $root = $this->catalogService->create([
            'name' => 'Root',
            'slug' => 'root',
            'parent_id' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $this->catalogService->create([
            'name' => 'Child',
            'slug' => 'child',
            'parent_id' => $root->id,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $tree = $this->catalogService->getTree();
        $this->assertCount(1, $tree);
        $this->assertCount(1, $tree->first()->children);
        $this->assertSame('Child', $tree->first()->children->first()->name);
    }

    public function test_mapping_creation(): void
    {
        $catalog = $this->catalogService->create([
            'name' => 'Catalog Cat',
            'slug' => 'catalog-cat',
            'is_active' => true,
        ]);
        $donor = DonorCategory::create([
            'external_id' => 'ext-1',
            'name' => 'Donor Cat',
            'slug' => 'donor-cat',
            'parser_enabled' => true,
        ]);
        $mapping = $this->mappingService->create([
            'donor_category_id' => $donor->id,
            'catalog_category_id' => $catalog->id,
            'confidence' => 100,
            'is_manual' => true,
        ]);
        $this->assertDatabaseHas('category_mapping', [
            'donor_category_id' => $donor->id,
            'catalog_category_id' => $catalog->id,
        ]);
        $this->assertSame($catalog->id, $mapping->catalog_category_id);
    }

    public function test_mapping_resolution(): void
    {
        $catalog = $this->catalogService->create([
            'name' => 'Resolved',
            'slug' => 'resolved',
            'is_active' => true,
        ]);
        $donor = DonorCategory::create([
            'external_id' => 'ext-2',
            'name' => 'Donor',
            'slug' => 'donor',
            'parser_enabled' => true,
        ]);
        $this->mappingService->create([
            'donor_category_id' => $donor->id,
            'catalog_category_id' => $catalog->id,
        ]);
        $resolvedId = $this->mappingService->resolveCatalogCategoryId($donor->id);
        $this->assertSame($catalog->id, $resolvedId);
        $resolved = $this->mappingService->resolveCatalogCategory($donor->id);
        $this->assertNotNull($resolved);
        $this->assertSame('resolved', $resolved->slug);
    }
}
