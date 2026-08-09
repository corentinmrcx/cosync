<?php declare(strict_types=1);

namespace App\Service;

use App\DTO\LicencieCreateData;
use App\DTO\LicencieIdentityData;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Enum\NatureLicence;
use App\Repository\LicencieRepository;
use App\Repository\TeamRepository;
use App\Service\Import\DataSanitizer;
use App\Service\Stock\DotationBesoinSynchronizer;
use Doctrine\ORM\EntityManagerInterface;

final class LicencieService
{
    public function __construct(
        private readonly DotationBesoinSynchronizer $dotationSynchronizer,
        private readonly EntityManagerInterface $em,
        private readonly LicencieRepository $licencieRepo,
        private readonly TeamRepository $teamRepo,
        private readonly DataSanitizer $sanitizer,
    ) {}

    /**
     * @throws \DomainException si un doublon est détecté (num_licence ou nom+prénom+naissance)
     */
    public function create(LicencieCreateData $data, Season $season): Licencie
    {
        $nom = mb_strtoupper(trim((string) $data->nom), 'UTF-8');
        $prenom = mb_convert_case(trim((string) $data->prenom), MB_CASE_TITLE, 'UTF-8');
        $email = $this->sanitizer->sanitizeEmail($data->email);
        $phone = $this->sanitizer->sanitizePhone($data->telephone);
        $numLicence = $data->numLicence !== null && trim($data->numLicence) !== ''
            ? $this->sanitizer->sanitizeNumLicence($data->numLicence)
            : null;

        if ($numLicence !== null && $this->licencieRepo->findByNumLicence($numLicence, $season) !== null) {
            throw new \DomainException(sprintf('Un licencié avec le numéro FootClubs "%s" existe déjà dans cette saison.', $numLicence));
        }

        $existing = $this->licencieRepo->findByNomPrenomNaissance($nom, $prenom, $data->dateNaissance, $season);
        if ($existing !== null) {
            throw new \DomainException(sprintf('%s %s (né(e) le %s) existe déjà dans cette saison.', $nom, $prenom, $data->dateNaissance->format('d/m/Y')));
        }

        $licencie = new Licencie();
        $licencie->setNom($nom);
        $licencie->setPrenom($prenom);
        $licencie->setDateNaissance($data->dateNaissance);
        $licencie->setCategory($data->category);
        $team = $data->team ?? $this->teamRepo->findForCategory($data->category, $season);
        $licencie->setTeam($team);
        $licencie->setSeason($season);
        $licencie->setEmail($email);
        $licencie->setTelephone($phone);
        $licencie->setVoieRue($data->voieRue !== null && trim($data->voieRue) !== '' ? trim($data->voieRue) : null);
        $licencie->setCodePostal($data->codePostal !== null && trim($data->codePostal) !== '' ? trim($data->codePostal) : null);
        $licencie->setVille($data->ville !== null && trim($data->ville) !== '' ? trim($data->ville) : null);
        $licencie->setNumLicence($numLicence);
        $licencie->setFormTokenExpiresAt(LienPublic::expiration());
        $licencie->setCreatedManually(true);
        // Saisie explicite par l'admin : elle fait autorité sur un import ultérieur.
        $licencie->setNatureLicence($data->natureLicence);
        $licencie->setNatureManuelle($data->natureLicence !== null);

        $dossier = new DossierClub();
        $dossier->setLicencie($licencie);
        $dossier->setStatus(LicenceStatus::IMPORTED);

        $this->em->persist($licencie);
        $this->em->persist($dossier);
        $this->em->flush();

        return $licencie;
    }

    /**
     * @throws \DomainException si le nouveau num_licence ou nom+prénom+naissance appartient déjà à un autre licencié
     */
    public function editIdentity(Licencie $licencie, LicencieIdentityData $data): void
    {
        $nom = mb_strtoupper(trim((string) $data->nom), 'UTF-8');
        $prenom = mb_convert_case(trim((string) $data->prenom), MB_CASE_TITLE, 'UTF-8');
        $email = $this->sanitizer->sanitizeEmail($data->email);
        $phone = $this->sanitizer->sanitizePhone($data->telephone);
        $numLicence = $data->numLicence !== null && trim($data->numLicence) !== ''
            ? $this->sanitizer->sanitizeNumLicence($data->numLicence)
            : null;

        $season = $licencie->getSeason();

        if ($numLicence !== null && $numLicence !== $licencie->getNumLicence()) {
            $other = $this->licencieRepo->findByNumLicence($numLicence, $season);
            if ($other !== null && !$other->getUuid()->equals($licencie->getUuid())) {
                throw new \DomainException(sprintf('Le numéro FootClubs "%s" est déjà utilisé par %s dans cette saison.', $numLicence, $other->getNomPrenom()));
            }
        }

        if ($nom !== $licencie->getNom() || $prenom !== $licencie->getPrenom() || $data->dateNaissance != $licencie->getDateNaissance()) {
            $other = $this->licencieRepo->findByNomPrenomNaissance($nom, $prenom, $data->dateNaissance, $season);
            if ($other !== null && !$other->getUuid()->equals($licencie->getUuid())) {
                throw new \DomainException(sprintf('%s %s (né(e) le %s) existe déjà dans cette saison.', $nom, $prenom, $data->dateNaissance->format('d/m/Y')));
            }
        }

        $licencie->setNom($nom);
        $licencie->setPrenom($prenom);
        $licencie->setDateNaissance($data->dateNaissance);
        $licencie->setCategory($data->category);
        $licencie->setEmail($email);
        $licencie->setTelephone($phone);
        $licencie->setVoieRue($data->voieRue !== null && trim($data->voieRue) !== '' ? trim($data->voieRue) : null);
        $licencie->setCodePostal($data->codePostal !== null && trim($data->codePostal) !== '' ? trim($data->codePostal) : null);
        $licencie->setVille($data->ville !== null && trim($data->ville) !== '' ? trim($data->ville) : null);
        $licencie->setNumLicence($numLicence);

        $this->em->flush();
    }

    public function edit(
        Licencie $licencie,
        ?string $tailleHaut,
        ?string $tailleBas,
        ?string $pointure,
        ?NatureLicence $natureLicence = null,
    ): void {
        $dossier = $licencie->getDossierClub();
        if ($dossier !== null) {
            $dossier->setTailleHaut($tailleHaut ?: null);
            $dossier->setTailleBas($tailleBas ?: null);
            $dossier->setPointure($pointure ?: null);
        }

        // Le verrou n'est posé que si la valeur change réellement : ouvrir puis réenregistrer
        // le formulaire sans y toucher ne doit pas figer la nature contre les futurs imports.
        $natureChangee = $natureLicence !== $licencie->getNatureLicence();
        if ($natureChangee) {
            $licencie->setNatureLicence($natureLicence);
            $licencie->setNatureManuelle(true);
        }

        $this->em->flush();

        // La nature pilote les options de dotation : la corriger change ce qui est dû.
        if ($natureChangee) {
            $this->dotationSynchronizer->recomputeForLicencie($licencie);
        }
    }
}
