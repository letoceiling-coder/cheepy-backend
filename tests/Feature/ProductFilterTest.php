<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\SystemProduct;
use App\Models\SystemProductAttribute;
use App\Services\Catalog\ProductFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFilterTest extends TestCase
{
    use RefreshDatabase;

    private ProductFilterService $filter;

    private int $catId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = app(ProductFilterService::class);
        $cat = CatalogCategory::create([
            'name' => 'Shoes',
            'slug' => 'shoes',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $this->catId = $cat->id;
    }

    public function test_filters_by_category_and_price(): void
    {
        SystemProduct::create([
            'name' => 'P1',
            'price_raw' => 1000,
            'category_id' => $this->catId,
            'status' => 'published',
        ]);
        SystemProduct::create([
            'name' => 'P2',
            'price_raw' => 5000,
            'category_id' => $this->catId,
            'status' => 'published',
        ]);
        SystemProduct::create([
            'name' => 'P3',
            'price_raw' => 500,
            'category_id' => $this->catId,
            'status' => 'published',
        ]);

        $results = $this->filter->get($this->catId, 1000.0, 3000.0, []);

        $this->assertCount(1, $results);
        $this->assertSame('P1', $results->first()->name);
    }

    public function test_filters_by_single_attribute(): void
    {
        $p1 = SystemProduct::create([
            'name' => 'Red Shirt',
            'price_raw' => 1000,
            'category_id' => $this->catId,
            'status' => 'published',
        ]);
        SystemProductAttribute::create([
            'system_product_id' => $p1->id,
            'attr_name' => 'color',
            'attr_value' => 'red',
            'attr_type' => 'text',
        ]);

        $p2 = SystemProduct::create([
            'name' => 'Blue Shirt',
            'price_raw' => 1000,
            'category_id' => $this->catId,
            'status' => 'published',
        ]);
        SystemProductAttribute::create([
            'system_product_id' => $p2->id,
            'attr_name' => 'color',
            'attr_value' => 'blue',
            'attr_type' => 'text',
        ]);

        $results = $this->filter->get($this->catId, null, null, ['color' => 'red']);

        $this->assertCount(1, $results);
        $this->assertSame('Red Shirt', $results->first()->name);
    }

    public function test_filters_by_multiple_attributes_and_logic(): void
    {
        $p1 = SystemProduct::create([
            'name' => 'Red 42',
            'price_raw' => 1000,
            'category_id' => $this->catId,
            'status' => 'published',
        ]);
        SystemProductAttribute::create([
            'system_product_id' => $p1->id,
            'attr_name' => 'color',
            'attr_value' => 'red',
            'attr_type' => 'text',
        ]);
        SystemProductAttribute::create([
            'system_product_id' => $p1->id,
            'attr_name' => 'size',
            'attr_value' => '42',
            'attr_type' => 'int',
            'value_int' => 42,
        ]);

        $p2 = SystemProduct::create([
            'name' => 'Red 44',
            'price_raw' => 1000,
            'category_id' => $this->catId,
            'status' => 'published',
        ]);
        SystemProductAttribute::create([
            'system_product_id' => $p2->id,
            'attr_name' => 'color',
            'attr_value' => 'red',
            'attr_type' => 'text',
        ]);
        SystemProductAttribute::create([
            'system_product_id' => $p2->id,
            'attr_name' => 'size',
            'attr_value' => '44',
            'attr_type' => 'int',
            'value_int' => 44,
        ]);

        $p3 = SystemProduct::create([
            'name' => 'Blue 42',
            'price_raw' => 1000,
            'category_id' => $this->catId,
            'status' => 'published',
        ]);
        SystemProductAttribute::create([
            'system_product_id' => $p3->id,
            'attr_name' => 'color',
            'attr_value' => 'blue',
            'attr_type' => 'text',
        ]);
        SystemProductAttribute::create([
            'system_product_id' => $p3->id,
            'attr_name' => 'size',
            'attr_value' => '42',
            'attr_type' => 'int',
            'value_int' => 42,
        ]);

        $results = $this->filter->get($this->catId, null, null, ['color' => 'red', 'size' => 42]);

        $this->assertCount(1, $results);
        $this->assertSame('Red 42', $results->first()->name);
    }

    public function test_cache_invalidation_on_product_create(): void
    {
        SystemProduct::create([
            'name' => 'P1',
            'price_raw' => 1000,
            'category_id' => $this->catId,
            'status' => 'published',
        ]);

        $results1 = $this->filter->get($this->catId, null, null, []);

        SystemProduct::create([
            'name' => 'P2',
            'price_raw' => 2000,
            'category_id' => $this->catId,
            'status' => 'published',
        ]);

        $results2 = $this->filter->get($this->catId, null, null, []);

        $this->assertCount(1, $results1);
        $this->assertCount(2, $results2);
    }
}
