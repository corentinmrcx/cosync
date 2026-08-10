<?php declare(strict_types=1);

namespace App\Service\Cle;

use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Repository\DetenteurRepository;
use App\Repository\DirigeantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Entrée d'une personne dans le registre des clés.
 *
 * Le parcours normal part d'un dirigeant de la saison : on ne resaisit pas une
 * identité déjà connue de l'effectif. L'opération est idempotente — désigner deux
 * fois la même personne ne crée pas deux détenteurs, sans quoi ses clés se
 * retrouveraient réparties sur deux lignes du registre.
 */
final class DetenteurService
{
    public function __construct(
        private readonly DetenteurRepository $detenteurRepo,
        private readonly DirigeantRepository $dirigeantRepo,
        private readonly DetenteurEffectifResolver $effectifResolver,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Résout la personne choisie dans le sélecteur du registre, qui mêle détenteurs
     * déjà connus et dirigeants de la saison. Désigner un dirigeant l'inscrit au
     * registre au passage : remettre une clé à quelqu'un ne doit pas demander de
     * l'ajouter d'abord dans un autre écran.
     *
     * @param string $reference « detenteur:12 » ou « dirigeant:<uuid> »
     *
     * @throws \DomainException référence inconnue ou mal formée
     */
    public function resoudre(string $reference): Detenteur
    {
        [$type, $identifiant] = array_pad(explode(':', $reference, 2), 2, '');

        if ($type === 'detenteur' && ctype_digit($identifiant)) {
            $detenteur = $this->detenteurRepo->find((int) $identifiant);

            if ($detenteur !== null) {
                return $detenteur;
            }
        }

        if ($type === 'dirigeant' && Uuid::isValid($identifiant)) {
            $dirigeant = $this->dirigeantRepo->findByUuid(Uuid::fromString($identifiant));

            if ($dirigeant !== null) {
                return $this->depuisDirigeant($dirigeant);
            }
        }

        throw new \DomainException('Personne introuvable.');
    }

    /** Le détenteur correspondant à ce dirigeant, créé au besoin. */
    public function depuisDirigeant(Dirigeant $dirigeant): Detenteur
    {
        $detenteur = $this->effectifResolver->detenteurDe($dirigeant);

        if ($detenteur === null) {
            $detenteur = (new Detenteur())
                ->setNom($dirigeant->getNom())
                ->setPrenom($dirigeant->getPrenom());

            $this->em->persist($detenteur);
        }

        // Les coordonnées suivent l'effectif : un mail à jour côté dirigeant doit
        // servir à l'envoi du lien de signature.
        $detenteur
            ->setNumLicence($dirigeant->getNumLicence() ?? $detenteur->getNumLicence())
            ->setEmail($dirigeant->getEmail() ?? $detenteur->getEmail())
            ->setTelephone($dirigeant->getTelephone() ?? $detenteur->getTelephone());

        $this->em->flush();

        return $detenteur;
    }

    /**
     * Détenteur extérieur à l'effectif : mairie, entreprise d'entretien, association
     * qui utilise le local.
     *
     * @throws \DomainException si la personne figure déjà au registre
     */
    public function creerExterieur(
        string $nom,
        string $prenom,
        ?string $qualite,
        ?string $email,
        ?string $telephone,
    ): Detenteur {
        if ($this->detenteurRepo->findByNomPrenom($nom, $prenom) !== null) {
            throw new \DomainException(sprintf('%s %s figure déjà au registre des clés.', $nom, $prenom));
        }

        $detenteur = (new Detenteur())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setQualite($qualite)
            ->setEmail($email)
            ->setTelephone($telephone);

        $this->em->persist($detenteur);
        $this->em->flush();

        return $detenteur;
    }
}
