<?php declare(strict_types=1);

namespace App\Service;

use App\DTO\DirigeantData;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Repository\DirigeantRepository;
use App\Service\Import\DataSanitizer;
use Doctrine\ORM\EntityManagerInterface;

final class DirigeantService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DirigeantRepository $dirigeantRepo,
        private readonly DataSanitizer $sanitizer,
    ) {}

    /**
     * @throws \DomainException si un doublon est détecté
     */
    public function create(DirigeantData $data, Season $season): Dirigeant
    {
        ['nom' => $nom, 'prenom' => $prenom] = $this->normalize($data);
        $numLicence = $this->normalizeNumLicence($data->numLicence);

        if ($numLicence !== null && $this->dirigeantRepo->findByNumLicence($numLicence) !== null) {
            throw new \DomainException(sprintf('Un dirigeant avec le numéro FootClubs "%s" existe déjà.', $numLicence));
        }

        if ($this->dirigeantRepo->findByNomPrenomSaison($nom, $prenom, $season) !== null) {
            throw new \DomainException(sprintf('%s %s existe déjà dans la liste des dirigeants pour cette saison.', $nom, $prenom));
        }

        $dirigeant = $this->hydrate(new Dirigeant(), $data, $nom, $prenom, $numLicence);
        $dirigeant->setSeason($season);
        $dirigeant->setCreatedManually(true);

        $this->em->persist($dirigeant);
        $this->em->flush();

        return $dirigeant;
    }

    /**
     * @throws \DomainException si le num_licence appartient déjà à un autre dirigeant
     */
    public function edit(Dirigeant $dirigeant, DirigeantData $data): void
    {
        ['nom' => $nom, 'prenom' => $prenom] = $this->normalize($data);
        $numLicence = $this->normalizeNumLicence($data->numLicence);

        if ($numLicence !== null && $numLicence !== $dirigeant->getNumLicence()) {
            $other = $this->dirigeantRepo->findByNumLicence($numLicence);
            if ($other !== null && !$other->getUuid()->equals($dirigeant->getUuid())) {
                throw new \DomainException(sprintf('Le numéro FootClubs "%s" est déjà utilisé par %s.', $numLicence, $other->getNomPrenom()));
            }
        }

        $this->hydrate($dirigeant, $data, $nom, $prenom, $numLicence);
        $this->em->flush();
    }

    private function hydrate(Dirigeant $dirigeant, DirigeantData $data, string $nom, string $prenom, ?string $numLicence): Dirigeant
    {
        $dirigeant->setNom($nom);
        $dirigeant->setPrenom($prenom);
        $dirigeant->setEmail($this->sanitizer->sanitizeEmail($data->email));
        $dirigeant->setTelephone($this->sanitizer->sanitizePhone($data->telephone));
        $dirigeant->setDateNaissance($data->dateNaissance);
        $dirigeant->setRole($data->role);
        $dirigeant->setTailleHaut($data->tailleHaut ?: null);
        $dirigeant->setTailleBas($data->tailleBas ?: null);
        $dirigeant->setPointure($data->pointure ?: null);
        $dirigeant->setTeam($data->team);
        $dirigeant->setNumLicence($numLicence);
        $dirigeant->setLicencie($data->licencie);

        return $dirigeant;
    }

    /** @return array{nom: string, prenom: string} */
    private function normalize(DirigeantData $data): array
    {
        return [
            'nom'    => mb_strtoupper(trim((string) $data->nom), 'UTF-8'),
            'prenom' => mb_convert_case(trim((string) $data->prenom), MB_CASE_TITLE, 'UTF-8'),
        ];
    }

    private function normalizeNumLicence(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        return $this->sanitizer->sanitizeNumLicence($raw);
    }
}
