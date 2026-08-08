<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentSignatureRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Signature d'un document par un licencié ou un dirigeant.
 *
 * La signature manuscrite n'est jamais stockée en base : elle est incrustée dans
 * le PDF, archivé sur Drive puis supprimé du disque local. Comme partout dans le
 * projet, drivePath porte un chemin local absolu tant que l'upload n'a pas eu
 * lieu, puis l'ID Drive du fichier.
 */
#[ORM\Entity(repositoryClass: DocumentSignatureRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_document_signature_dirigeant', columns: ['document_id', 'dirigeant_uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_document_signature_licencie', columns: ['document_id', 'licencie_uuid'])]
class DocumentSignature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentSignable $document;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'dirigeant_uuid', referencedColumnName: 'uuid', nullable: true, onDelete: 'CASCADE')]
    private ?Dirigeant $dirigeant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'licencie_uuid', referencedColumnName: 'uuid', nullable: true, onDelete: 'CASCADE')]
    private ?Licencie $licencie = null;

    #[ORM\Column]
    private \DateTimeImmutable $signedAt;

    /** Chemin local temporaire puis ID Drive du PDF signé */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $drivePath = null;

    public function __construct()
    {
        $this->signedAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }

    public function getDocument(): DocumentSignable { return $this->document; }
    public function setDocument(DocumentSignable $document): static { $this->document = $document; return $this; }

    public function getDirigeant(): ?Dirigeant { return $this->dirigeant; }
    public function setDirigeant(?Dirigeant $dirigeant): static { $this->dirigeant = $dirigeant; return $this; }

    public function getLicencie(): ?Licencie { return $this->licencie; }
    public function setLicencie(?Licencie $licencie): static { $this->licencie = $licencie; return $this; }

    public function getSignedAt(): \DateTimeImmutable { return $this->signedAt; }
    public function setSignedAt(\DateTimeImmutable $signedAt): static { $this->signedAt = $signedAt; return $this; }

    public function getDrivePath(): ?string { return $this->drivePath; }
    public function setDrivePath(?string $drivePath): static { $this->drivePath = $drivePath; return $this; }

    /** Le PDF est-il encore sur le disque local, en attente d'upload Drive ? */
    public function isUploadPending(): bool
    {
        return $this->drivePath !== null && str_starts_with($this->drivePath, '/');
    }

    /** Le PDF est-il archivé sur Drive ? */
    public function isArchived(): bool
    {
        return $this->drivePath !== null && !str_starts_with($this->drivePath, '/');
    }

    public function getNom(): string
    {
        return $this->dirigeant?->getNom() ?? $this->licencie?->getNom() ?? '';
    }

    public function getPrenom(): string
    {
        return $this->dirigeant?->getPrenom() ?? $this->licencie?->getPrenom() ?? '';
    }
}
