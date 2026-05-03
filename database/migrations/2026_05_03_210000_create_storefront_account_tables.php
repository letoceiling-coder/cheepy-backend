<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('birthday')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->json('preferences')->nullable();
            $table->timestamps();
        });

        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120)->nullable();
            $table->string('country', 80)->default('Россия');
            $table->string('region', 160)->nullable();
            $table->string('city', 160);
            $table->string('postal_code', 32)->nullable();
            $table->string('line1', 500);
            $table->string('line2', 255)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('source', 32)->default('manual');
            $table->boolean('is_default')->default(false);
            $table->json('provider_payload')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_default']);
        });

        Schema::create('user_pickup_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('cdek');
            $table->string('office_code', 120);
            $table->string('name', 255)->nullable();
            $table->string('city', 160)->nullable();
            $table->string('address', 500);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('work_time', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('provider_payload')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider', 'office_code']);
            $table->index(['user_id', 'is_default']);
        });

        Schema::create('customer_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('method_type', 64)->default('card');
            $table->text('provider_token_encrypted')->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_default']);
        });

        Schema::create('customer_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64)->unique();
            $table->string('status', 40)->default('new');
            $table->unsignedBigInteger('subtotal_amount')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('delivery_amount')->default(0);
            $table->unsignedBigInteger('bonus_spent_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->string('payment_status', 40)->default('pending');
            $table->string('delivery_provider', 40)->nullable();
            $table->string('delivery_type', 40)->nullable();
            $table->json('delivery_snapshot')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('customer_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('customer_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name', 500);
            $table->string('product_image')->nullable();
            $table->string('sku', 120)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('total_price')->default(0);
            $table->json('attributes')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('customer_orders')->nullOnDelete();
            $table->string('number', 120)->unique();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->string('fiscal_url')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'issued_at']);
        });

        Schema::create('customer_wallet_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->bigInteger('balance_after')->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->string('kind', 40);
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 500);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 255);
            $table->string('description', 500)->nullable();
            $table->string('discount_type', 20);
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->unsignedBigInteger('min_order_amount')->default(0);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_user')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->string('target', 40)->default('all');
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('rules')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'expires_at']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('customer_orders')->nullOnDelete();
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'coupon_id']);
        });

        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('referral_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_code_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_hash', 128)->nullable();
            $table->string('ip_hash', 128)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('clicked_at');
            $table->timestamps();
            $table->index(['referral_code_id', 'clicked_at']);
        });

        Schema::create('referral_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referral_code_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->unsignedBigInteger('reward_amount')->default(0);
            $table->timestamp('reward_granted_at')->nullable();
            $table->timestamps();
            $table->index(['referrer_user_id', 'event_type']);
        });

        Schema::create('bonus_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('title', 255);
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_rules');
        Schema::dropIfExists('referral_events');
        Schema::dropIfExists('referral_link_clicks');
        Schema::dropIfExists('referral_codes');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('customer_wallet_ledger');
        Schema::dropIfExists('customer_receipts');
        Schema::dropIfExists('customer_order_items');
        Schema::dropIfExists('customer_orders');
        Schema::dropIfExists('customer_payment_methods');
        Schema::dropIfExists('user_pickup_points');
        Schema::dropIfExists('user_addresses');
        Schema::dropIfExists('customer_profiles');
    }
};
