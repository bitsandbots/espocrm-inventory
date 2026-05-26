<?php

namespace Espo\Modules\Inventory\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Inventory\Services\CcInventoryDbService;
use Espo\Modules\Inventory\Services\CcInventorySyncService;
use stdClass;
use Throwable;

class InventorySync
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private User $user,
        private Log $log
    ) {
        if (!$this->user->isAdmin()) {
            throw new Forbidden("Admin access required.");
        }
    }

    public function postActionTestConnection(Request $request): stdClass
    {
        $dbService = $this->injectableFactory->create(CcInventoryDbService::class);
        $dbService->testConnection();

        $result = new stdClass();
        $result->success = true;
        $result->message = "Connection successful.";

        return $result;
    }

    public function postActionRunSync(Request $request): stdClass
    {
        $syncService = $this->injectableFactory->create(CcInventorySyncService::class);

        try {
            $count = $syncService->runFullSync();
        } catch (Throwable $e) {
            $this->log->error("CC Inventory manual sync failed: " . $e->getMessage());

            try {
                $syncService->recordSyncError($e->getMessage());
            } catch (Throwable) {}

            throw $e;
        }

        $result = new stdClass();
        $result->success = true;
        $result->count   = $count;

        return $result;
    }
}
