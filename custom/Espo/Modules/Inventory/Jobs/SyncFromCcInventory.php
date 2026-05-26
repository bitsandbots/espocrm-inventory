<?php

namespace Espo\Modules\Inventory\Jobs;

use Espo\Core\InjectableFactory;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\Inventory\Services\CcInventorySyncService;
use Throwable;

class SyncFromCcInventory implements JobDataLess
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private Log $log
    ) {}

    public function run(): void
    {
        $syncService = $this->injectableFactory->create(CcInventorySyncService::class);

        try {
            $count = $syncService->runFullSync();
            $this->log->info("CC Inventory sync completed. {$count} records processed.");
        } catch (Throwable $e) {
            $this->log->error("CC Inventory sync failed: " . $e->getMessage());

            try {
                $syncService->recordSyncError($e->getMessage());
            } catch (Throwable) {}
        }
    }
}
