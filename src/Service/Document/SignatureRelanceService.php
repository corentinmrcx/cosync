<?php declare(strict_types=1);

namespace App\Service\Document;

use App\DTO\RelanceResultat;
use App\DTO\RelanceSignature;
use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Repository\DirigeantRepository;
use App\Repository\LicencieRepository;
use App\Service\Mail\DirigeantLinkService;
use App\Service\Mail\InscriptionLinkService;
use Symfony\Component\Uid\Uuid;

/**
 * Relancer les personnes à qui il manque une signature.
 *
 * Un document ajouté en cours de saison ne se voit pas : les dossiers concernés étaient
 * complets, donc leurs liens consommés. Sans relance, personne ne le signera jamais.
 *
 * Deux principes tiennent cette classe :
 *
 * - **Le regroupement est par personne, pas par document.** Les parcours publics
 *   présentent tous les documents manquants d'un coup ; relancer document par document
 *   enverrait deux mails à qui en doit deux.
 * - **La liste éligible est recalculée ici, dans les deux sens.** L'écran l'affiche, et
 *   l'envoi la repasse au crible plutôt que de croire les uuid postés : un uuid ajouté au
 *   formulaire ne peut pas faire écrire à quelqu'un qui n'était pas proposé.
 *
 * Les deux populations reçoivent un mail différent : le dirigeant son lien de formulaire,
 * qui couvre tout ce qui lui manque encore ; le joueur un lien de signature seule, son
 * dossier n'ayant rien d'autre à compléter.
 */
final class SignatureRelanceService
{
    public function __construct(
        private readonly LicencieRepository $licencieRepo,
        private readonly DirigeantRepository $dirigeantRepo,
        private readonly SignatureCompletionService $signatureCompletion,
        private readonly DocumentRequirementResolver $requirementResolver,
        private readonly InscriptionLinkService $inscriptionLinkService,
        private readonly DirigeantLinkService $dirigeantLinkService,
    ) {}

    /** @return RelanceSignature[] */
    public function licencies(Season $season): array
    {
        $lignes = [];

        foreach ($this->licencieRepo->findBySeason($season) as $licencie) {
            // `manquants()` écarte lui-même les dossiers non terminés : leur formulaire
            // d'inscription leur présentera ce document avec le reste.
            $manquants = $this->signatureCompletion->manquants($licencie);

            if ($manquants !== []) {
                $lignes[] = new RelanceSignature(
                    (string) $licencie->getUuid(),
                    $licencie->getNomPrenom(),
                    $licencie->getEmail(),
                    $licencie->getTeam()?->getName() ?? $licencie->getCategory()->getLabel(),
                    $manquants,
                );
            }
        }

        return $lignes;
    }

    /** @return RelanceSignature[] */
    public function dirigeants(Season $season): array
    {
        $lignes = [];

        foreach ($this->dirigeantRepo->findBySeason($season) as $dirigeant) {
            // Une licence administrative n'attend aucun document : le resolver le sait déjà.
            $manquants = $this->requirementResolver->manquantsPourDirigeant($dirigeant);

            if ($manquants !== []) {
                $lignes[] = new RelanceSignature(
                    (string) $dirigeant->getUuid(),
                    $dirigeant->getNomPrenom(),
                    $dirigeant->getEmail(),
                    $dirigeant->getRole()->label(),
                    $manquants,
                );
            }
        }

        return $lignes;
    }

    /**
     * Uuid de ceux qu'on peut réellement joindre — cochés d'office à l'ouverture de
     * l'écran. Calculé ici plutôt que dans le template : Twig ne sait pas réindexer un
     * tableau filtré, et un tableau troué se sérialiserait en objet JSON.
     *
     * @param RelanceSignature[] $lignes
     *
     * @return string[]
     */
    public function uuidsJoignables(array $lignes): array
    {
        return array_values(array_map(
            static fn (RelanceSignature $ligne): string => $ligne->uuid,
            array_filter($lignes, static fn (RelanceSignature $ligne): bool => $ligne->estJoignable()),
        ));
    }

    /** @param string[] $uuidsRetenus */
    public function relancerLicencies(Season $season, array $uuidsRetenus): RelanceResultat
    {
        $retenus = array_flip($uuidsRetenus);
        $envoyes = 0;
        $sansEmail = 0;

        foreach ($this->licencies($season) as $ligne) {
            if (!isset($retenus[$ligne->uuid])) {
                continue;
            }

            if (!$ligne->estJoignable()) {
                ++$sansEmail;

                continue;
            }

            $licencie = $this->licencieRepo->findByUuid(Uuid::fromString($ligne->uuid));

            if ($licencie instanceof Licencie) {
                $this->inscriptionLinkService->sendSignature($licencie);
                ++$envoyes;
            }
        }

        return new RelanceResultat($envoyes, $sansEmail);
    }

    /** @param string[] $uuidsRetenus */
    public function relancerDirigeants(Season $season, array $uuidsRetenus): RelanceResultat
    {
        $retenus = array_flip($uuidsRetenus);
        $envoyes = 0;
        $sansEmail = 0;

        foreach ($this->dirigeants($season) as $ligne) {
            if (!isset($retenus[$ligne->uuid])) {
                continue;
            }

            if (!$ligne->estJoignable()) {
                ++$sansEmail;

                continue;
            }

            $dirigeant = $this->dirigeantRepo->findByUuid(Uuid::fromString($ligne->uuid));

            if ($dirigeant instanceof Dirigeant) {
                $this->dirigeantLinkService->send($dirigeant);
                ++$envoyes;
            }
        }

        return new RelanceResultat($envoyes, $sansEmail);
    }
}
