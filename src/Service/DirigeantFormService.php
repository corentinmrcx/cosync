<?php declare(strict_types=1);

namespace App\Service;

use App\DTO\DirigeantPublicFormData;
use App\Entity\Dirigeant;
use Doctrine\ORM\EntityManagerInterface;

final class DirigeantFormService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function submit(Dirigeant $dirigeant, DirigeantPublicFormData $data): void
    {
        $dirigeant
            ->setTailleHaut($data->tailleHaut)
            ->setTailleBas($data->tailleBas)
            ->setPointure($data->pointure)
            ->setFormCompletedAt(new \DateTimeImmutable());

        $this->em->flush();
    }
}
