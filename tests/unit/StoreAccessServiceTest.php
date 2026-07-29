<?php

use App\Models\StoreModel;
use App\Services\StoreAccessService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class StoreAccessServiceTest extends CIUnitTestCase
{
    public function testResolveUsesFirstAccessibleStoreWhenNoStoreIsRequested(): void
    {
        $model = $this->storeModel(
            [['id' => 12, 'store_name' => 'Main Store']],
            [12]
        );

        $resolved = (new StoreAccessService($model))->resolve(7, 'store_system');

        $this->assertSame(12, $resolved['id'] ?? null);
        $this->assertSame('Main Store', $resolved['store_name'] ?? null);
    }

    public function testResolveReturnsRequestedAccessibleStore(): void
    {
        $model = $this->storeModel(
            [
                ['id' => 12, 'store_name' => 'Main Store'],
                ['id' => 18, 'store_name' => 'Technology Store'],
            ],
            [12, 18]
        );

        $resolved = (new StoreAccessService($model))->resolve(7, 'ADMIN', 18);

        $this->assertSame(18, $resolved['id'] ?? null);
        $this->assertSame('Technology Store', $resolved['store_name'] ?? null);
    }

    public function testResolveRejectsStoreOutsideUserScope(): void
    {
        $model = $this->storeModel(
            [['id' => 12, 'store_name' => 'Main Store']],
            [12]
        );

        $this->assertNull((new StoreAccessService($model))->resolve(7, 'STORE_SYSTEM', 99));
    }

    public function testResolveRejectsMissingUserOrEmptyScope(): void
    {
        $model = $this->storeModel([], []);
        $service = new StoreAccessService($model);

        $this->assertSame([], $service->accessibleStores(0, 'ADMIN'));
        $this->assertNull($service->resolve(7, 'STORE_SYSTEM'));
    }

    private function storeModel(array $stores, array $allowedStoreIds): StoreModel
    {
        return new class ($stores, $allowedStoreIds) extends StoreModel {
            public function __construct(
                private array $stores,
                private array $allowedStoreIds
            ) {
            }

            public function getAccessibleStores(int $userId, string $role): array
            {
                return $this->stores;
            }

            public function canUserAccessStore(int $userId, string $role, int $storeId): bool
            {
                return in_array($storeId, $this->allowedStoreIds, true);
            }
        };
    }
}
