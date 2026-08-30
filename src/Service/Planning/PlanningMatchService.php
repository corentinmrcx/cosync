<?php declare(strict_types=1);

namespace App\Service\Planning;

use App\DTO\Planning\MatchImporteData;
use App\DTO\Planning\PlanningMatchData;
use App\Entity\MatchDomicile;
use App\Entity\Season;
use App\Enum\MatchSource;
use App\Repository\MatchDomicileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les gestes du club sur son planning : saisir, corriger, masquer, détacher de la FFF.
 *
 * Tout ce qui touche une ligne passe par ici — la synchronisation fédérale comprise, qui
 * appelle `appliquerDepuisFff()`. Écrire dans `MatchDomicile` depuis deux endroits ferait
 * diverger la règle d'autorité (§ « Qui possède quoi » de l'entité) au premier ajout.
 */
final class PlanningMatchService
{
    public function __construct(
        private readonly MatchDomicileRepository $matchRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return MatchDomicile[] */
    public function listerPourAdmin(Season $season): array
    {
        return $this->matchRepo->findParSaison($season);
    }

    public function creer(PlanningMatchData $data, Season $season): MatchDomicile
    {
        $match = (new MatchDomicile())
            ->setSeason($season)
            ->setSource(MatchSource::MANUEL);

        $this->appliquer($match, $data);

        $this->em->persist($match);
        $this->em->flush();

        return $match;
    }

    /**
     * Corrige une ligne saisie à la main.
     *
     * @throws \DomainException si la ligne suit encore le calendrier fédéral — la
     *                          correction serait effacée à la synchronisation suivante,
     *                          et personne ne le verrait. Il faut détacher d'abord.
     */
    public function modifier(MatchDomicile $match, PlanningMatchData $data): void
    {
        if (!$match->estModifiable()) {
            throw new \DomainException('Ce match suit le calendrier FFF : détachez-le avant de le corriger, sinon la prochaine synchronisation écraserait votre correction.');
        }

        $this->appliquer($match, $data);
        $this->em->flush();
    }

    /**
     * La note se change sur toutes les lignes, fédérales comprises : c'est justement la
     * part du document que le club possède.
     */
    public function changerNote(MatchDomicile $match, ?string $note): void
    {
        $match->setNote($this->nettoyer($note));
        $this->em->flush();
    }

    public function basculerMasque(MatchDomicile $match): void
    {
        $match->setMasque(!$match->isMasque());
        $this->em->flush();
    }

    public function detacher(MatchDomicile $match): void
    {
        $match->detacherDeLaFff();
        $this->em->flush();
    }

    public function reprendreLaFff(MatchDomicile $match): void
    {
        $match->reprendreLaFff();
        $this->em->flush();
    }

    public function supprimer(MatchDomicile $match): void
    {
        $this->em->remove($match);
        $this->em->flush();
    }

    /**
     * Enregistre un lot venu du collage.
     *
     * Les lignes entrent en `MANUEL` : un texte collé n'est rattaché à aucun `ma_no`,
     * donc à rien que la synchronisation saurait réconcilier. Les traiter comme
     * fédérales les ferait effacer au premier passage du robot.
     *
     * @param list<MatchImporteData> $matchs
     *
     * @return int nombre de lignes enregistrées
     */
    public function importerLot(array $matchs, Season $season): int
    {
        foreach ($matchs as $importe) {
            $match = (new MatchDomicile())
                ->setSeason($season)
                ->setSource(MatchSource::MANUEL)
                ->setDate($importe->date)
                ->setHeure($importe->heure)
                ->setCategorie($importe->categorie)
                ->setAdversaire($importe->adversaire)
                ->setNote($importe->note);

            $this->em->persist($match);
        }

        $this->em->flush();

        return count($matchs);
    }

    /**
     * Écrit sur une ligne ce que le district annonce.
     *
     * Ni la note ni le masque n'y sont touchés : c'est la part du club, et la
     * synchronisation n'a pas à la reprendre.
     *
     * @return bool true si quelque chose a changé — c'est ce qui distingue, dans le
     *              rapport, un match déplacé d'un match simplement revu
     */
    public function appliquerDepuisFff(MatchDomicile $match, MatchImporteData $data): bool
    {
        $avant = [
            $match->getDate()->format('Y-m-d'),
            $match->getHeure(),
            $match->getCategorie(),
            $match->getAdversaire(),
            $match->getFffCompetition(),
            $match->getFffTerrain(),
        ];

        $match
            ->setDate($data->date)
            ->setHeure($data->heure)
            ->setCategorie($data->categorie)
            ->setAdversaire($data->adversaire)
            ->setFffCompetition($data->fffCompetition)
            ->setFffTerrain($data->fffTerrain);

        return $avant !== [
            $match->getDate()->format('Y-m-d'),
            $match->getHeure(),
            $match->getCategorie(),
            $match->getAdversaire(),
            $match->getFffCompetition(),
            $match->getFffTerrain(),
        ];
    }

    /** Crée la ligne d'un match fédéral inconnu jusqu'ici. */
    public function creerDepuisFff(MatchImporteData $data, Season $season): MatchDomicile
    {
        $match = (new MatchDomicile())
            ->setSeason($season)
            ->setSource(MatchSource::FFF)
            ->setFffMaNo($data->fffMaNo);

        $this->appliquerDepuisFff($match, $data);
        $this->em->persist($match);

        return $match;
    }

    private function appliquer(MatchDomicile $match, PlanningMatchData $data): void
    {
        if ($data->date === null || $data->categorie === null || trim($data->categorie) === '') {
            throw new \DomainException('La date et la catégorie sont obligatoires.');
        }

        $match
            // Minuit : la colonne est un DATE, une heure résiduelle n'y servirait à rien
            // et ferait échouer les comparaisons de bornes de période.
            ->setDate($data->date->setTime(0, 0))
            ->setHeure($this->nettoyer($data->heure))
            ->setCategorie(trim($data->categorie))
            ->setAdversaire($this->nettoyer($data->adversaire))
            ->setNote($this->nettoyer($data->note));
    }

    /** Une chaîne vide vaut null : le planning n'affiche pas de champ « rempli de rien ». */
    private function nettoyer(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }
}
