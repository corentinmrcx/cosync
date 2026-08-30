<?php declare(strict_types=1);

namespace App\Service\Dirigeant;

use App\DTO\DirigeantData;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Enum\ChampContact;
use App\Enum\DirigeantRole;
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

        if ($numLicence !== null && $this->dirigeantRepo->findByNumLicence($numLicence, $season) !== null) {
            throw new \DomainException(sprintf('Un dirigeant avec le numéro FootClubs "%s" existe déjà dans cette saison.', $numLicence));
        }

        if ($this->dirigeantRepo->findByNomPrenomSaison($nom, $prenom, $season) !== null) {
            throw new \DomainException(sprintf('%s %s existe déjà dans la liste des dirigeants pour cette saison.', $nom, $prenom));
        }

        $dirigeant = $this->hydrate(new Dirigeant(), $data, $nom, $prenom, $numLicence);
        $dirigeant->setSeason($season);
        $dirigeant->setCreatedManually(true);

        $this->em->persist($dirigeant);
        $this->em->flush();

        // Aucun mail ici : le départ du lien est une décision, prise à la case du formulaire
        // de création ou plus tard depuis la fiche ou l'écran d'envoi groupé.
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
            $other = $this->dirigeantRepo->findByNumLicence($numLicence, $dirigeant->getSeason());
            if ($other !== null && !$other->getUuid()->equals($dirigeant->getUuid())) {
                throw new \DomainException(sprintf('Le numéro FootClubs "%s" est déjà utilisé par %s dans cette saison.', $numLicence, $other->getNomPrenom()));
            }
        }

        // Une coordonnée saisie par l'admin fait autorité sur l'export : on pose le verrou dès
        // qu'elle change, sinon le prochain import ramènerait la valeur qu'il vient de corriger.
        // Relevé avant hydrate(), qui écrase les valeurs.
        $emailChange = $this->sanitizer->sanitizeEmail($data->email) !== $dirigeant->getEmail();
        $telephoneChange = $this->sanitizer->sanitizePhone($data->telephone) !== $dirigeant->getTelephone();

        $this->hydrate($dirigeant, $data, $nom, $prenom, $numLicence);

        if ($emailChange) {
            $dirigeant->setEmailManuel(true);
        }
        if ($telephoneChange) {
            $dirigeant->setTelephoneManuel(true);
        }

        $this->em->flush();
    }

    /**
     * Relâche le verrou : l'import FootClubs redevient la source de vérité pour ce champ.
     * La valeur corrigée reste en place jusqu'au prochain import, qui la remplacera.
     */
    public function reprendreImport(Dirigeant $dirigeant, ChampContact $champ): void
    {
        match ($champ) {
            ChampContact::EMAIL => $dirigeant->setEmailManuel(false),
            ChampContact::TELEPHONE => $dirigeant->setTelephoneManuel(false),
        };

        $this->em->flush();
    }

    /**
     * Le club a signé cette licence dans FootClubs. Dernier état du parcours dirigeant.
     *
     * Aucun mail : c'est une démarche interne, le dirigeant n'a rien à en faire. Pendant de
     * {@see \App\Service\Licencie\PaiementService::validerSurFootclubs()}.
     */
    public function validerSurFootclubs(Dirigeant $dirigeant): void
    {
        if ($dirigeant->getValidatedFffAt() !== null) {
            return;
        }

        $dirigeant->setValidatedFffAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /** Sortie de secours d'un clic malheureux : la licence redevient à valider. */
    public function annulerValidationFootclubs(Dirigeant $dirigeant): void
    {
        $dirigeant->setValidatedFffAt(null);
        $this->em->flush();
    }

    /**
     * Validation groupée. La liste éligible est repassée au crible ici, jamais crue sur parole :
     * un uuid ajouté au formulaire posté ne doit pas pouvoir valider une fiche qui n'était pas
     * proposée à l'écran.
     *
     * @param Dirigeant[] $eligibles
     * @param string[]    $uuidsRetenus
     *
     * @return int nombre de licences validées
     */
    public function validerSurFootclubsEnMasse(array $eligibles, array $uuidsRetenus): int
    {
        $retenus = array_flip($uuidsRetenus);
        $valides = 0;

        foreach ($eligibles as $dirigeant) {
            if (!isset($retenus[(string) $dirigeant->getUuid()])) {
                continue;
            }

            $this->validerSurFootclubs($dirigeant);
            ++$valides;
        }

        return $valides;
    }

    private function hydrate(Dirigeant $dirigeant, DirigeantData $data, string $nom, string $prenom, ?string $numLicence): Dirigeant
    {
        $dirigeant->setNom($nom);
        $dirigeant->setPrenom($prenom);
        $dirigeant->setEmail($this->sanitizer->sanitizeEmail($data->email));
        $dirigeant->setTelephone($this->sanitizer->sanitizePhone($data->telephone));
        $dirigeant->setDateNaissance($data->dateNaissance);
        $dirigeant->setRole($data->role ?? DirigeantRole::DIRIGEANT);
        $dirigeant->setTailleHaut($data->tailleHaut ?: null);
        $dirigeant->setTailleBas($data->tailleBas ?: null);
        $dirigeant->setPointure($data->pointure ?: null);
        $dirigeant->setTeam($data->team);
        $dirigeant->setNumLicence($numLicence);
        $dirigeant->setLicencie($data->licencie);
        $dirigeant->setLicenceAdministrative($data->licenceAdministrative);

        return $dirigeant;
    }

    /** @return array{nom: string, prenom: string} */
    private function normalize(DirigeantData $data): array
    {
        return [
            'nom' => mb_strtoupper(trim((string) $data->nom), 'UTF-8'),
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
