<?php

namespace Espo\Modules\Inventory\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use Throwable;

class CcInventoryDbService
{
    private ?PDO $pdo = null;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function getConnection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'CcInventory');

        if (!$integration || !$integration->get('enabled')) {
            throw new Error("CC Inventory integration is not enabled.");
        }

        $host = $integration->get('dbHost') ?: 'localhost';
        $port = $integration->get('dbPort') ?: '3306';
        $name = $integration->get('dbName');
        $user = $integration->get('dbUser');
        $pass = $integration->get('dbPassword');

        if (!$name || !$user) {
            throw new Error("CC Inventory integration is missing required database credentials.");
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            $this->log->error("CC Inventory DB connection failed: " . $e->getMessage());
            throw new Error("CC Inventory: could not connect to database — " . $e->getMessage());
        }

        return $this->pdo;
    }

    public function testConnection(): bool
    {
        try {
            $db = $this->getConnection();
            $db->query("SELECT 1");
            return true;
        } catch (Throwable $e) {
            throw new Error("CC Inventory connection test failed: " . $e->getMessage());
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->getConnection()->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->getConnection()->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
