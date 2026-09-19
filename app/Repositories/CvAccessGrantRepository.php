<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

final class CvAccessGrantRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $cvDocumentId, int $visitorId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM cv_access_grants WHERE cv_document_id = :cv_document_id AND visitor_id = :visitor_id',
        );
        $statement->execute(['cv_document_id' => $cvDocumentId, 'visitor_id' => $visitorId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Clears every grant for a document. Called whenever the owner replaces
     * the CV's content, since a grant is a purchase of that specific content,
     * not a standing subscription to whatever the owner uploads later.
     */
    public function revokeAllForDocument(int $cvDocumentId): void
    {
        $statement = $this->connection->prepare('DELETE FROM cv_access_grants WHERE cv_document_id = :cv_document_id');
        $statement->execute(['cv_document_id' => $cvDocumentId]);
    }

    /**
     * Idempotent: if a grant already exists for this (document, visitor) pair
     * (e.g. a concurrent request raced this one), the existing grant is kept.
     */
    public function grant(int $cvDocumentId, int $visitorId, ?string $paymentReference): void
    {
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO cv_access_grants (cv_document_id, visitor_id, payment_reference)
                 VALUES (:cv_document_id, :visitor_id, :payment_reference)',
            );
            $statement->execute([
                'cv_document_id' => $cvDocumentId,
                'visitor_id' => $visitorId,
                'payment_reference' => $paymentReference,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }
}
