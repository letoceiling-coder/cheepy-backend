<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\CatalogCategory;
use App\Support\Testing\SafeApiTestingGuards;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds data ONLY for isolated API checks against the dedicated MySQL test database.
 * Never run against production (refuses wrong database name and non-testing APP_ENV).
 */
class SafeApiTestingSeeder extends Seeder
{
    public const TEST_ADMIN_EMAIL = 'api_safe_test@testing.invalid';

    public const TEST_ADMIN_PASSWORD = 'SafeApiTest_Only_2026!';

    /** Isolated donor row — all mapping tests must use id >= this range only on test DB. */
    public const ISOLATED_DONOR_ID = 1_000_001;

    public function run(): void
    {
        SafeApiTestingGuards::assertTestingDatabase();

        AdminUser::query()->updateOrCreate(
            ['email' => self::TEST_ADMIN_EMAIL],
            [
                'name' => 'API Safe Test Admin',
                'password' => Hash::make(self::TEST_ADMIN_PASSWORD),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $catalog = CatalogCategory::query()->firstOrCreate(
            ['slug' => 'api-safe-catalog-isolation'],
            [
                'name' => 'API Safe Catalog',
                'parent_id' => null,
                'sort_order' => 0,
                'is_active' => true,
            ]
        );

        $now = now();
        DB::table('donor_categories')->updateOrInsert(
            ['id' => self::ISOLATED_DONOR_ID],
            [
                'external_id' => 'test_isolation_'.self::ISOLATED_DONOR_ID,
                'name' => 'API Isolation Donor',
                'slug' => 'api-isolation-donor-'.self::ISOLATED_DONOR_ID,
                'parent_id' => null,
                'source_url' => null,
                'parser_enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $this->bumpDonorAutoIncrementIfMysql();
    }

    private function bumpDonorAutoIncrementIfMysql(): void
    {
        if (config('database.default') !== 'mysql') {
            return;
        }
        $next = max(
            (int) DB::table('donor_categories')->max('id'),
            self::ISOLATED_DONOR_ID
        ) + 1;
        DB::statement('ALTER TABLE donor_categories AUTO_INCREMENT = '.(int) $next);
    }
}
