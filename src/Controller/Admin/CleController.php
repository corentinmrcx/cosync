<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\CleMouvementData;
use App\DTO\FiltreListe;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CleDetentionStatut;
use App\Enum\CleMouvementType;
use App\Enum\Permission;
use App\Repository\DetenteurRepository;
use App\Security\CsrfGuard;
use App\Service\Cle\AttestationCleService;
use App\Service\Cle\CleRegistrePresenter;
use App\Service\Cle\CleRegistreService;
use App\Service\Cle\DetenteurEffectifResolver;
use App\Service\Cle\DetenteurLicenceSynchronizer;
use App\Service\Cle\DetenteurService;
use App\Service\Ui\ListFilterMemory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Registre des clés du local. Le registre lui-même est au niveau du club — un
 * trousseau ne change pas de main au 1er juillet — tandis que les attestations
 * portent sur la saison sélectionnée dans la navbar, l'engagement étant annuel.
 *
 * Trois écrans, comme le stock : un accueil de section qui ne porte que ce qui appelle
 * une action et les accès rapides, le registre des détenteurs, et l'historique complet
 * des mouvements.
 */
#[Route('/admin/cles', name: 'admin_cles_')]
#[IsGranted(Permission::CLE_LIRE->value)]
class CleController extends AbstractController
{
    private const MOUVEMENTS_PAR_PAGE = 25;

    public function __construct(
        private readonly CleRegistreService $registre,
        private readonly CleRegistrePresenter $presenter,
        private readonly DetenteurService $detenteurService,
        private readonly DetenteurLicenceSynchronizer $licenceSync,
        private readonly DetenteurEffectifResolver $effectifResolver,
        private readonly AttestationCleService $attestations,
        private readonly DetenteurRepository $detenteurRepo,
        private readonly ListFilterMemory $filterMemory,
        private readonly CsrfGuard $csrf,
    ) {}

    /**
     * Accueil de la section : ce qui appelle une action, et les deux accès. Aucune
     * synthèse chiffrée pour elle-même — un compteur qu'on ne suit pas dans le temps
     * n'est pas un indicateur, il occupe l'écran sans rien décider.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[CurrentSeason] Season $season): Response
    {
        $this->licenceSync->pourSaison($season);

        $lignes = $this->presenter->lignes($season);

        return $this->render('admin/cles/index.html.twig', [
            'season' => $season,
            'stats' => $this->presenter->stats($lignes),
            'nbEnAttente' => count($this->presenter->enAttenteDeSignature($lignes)),
            'nbMouvements' => $this->registre->compterMouvements(),
        ]);
    }

    /** Le registre : qui détient quoi, et où en est son engagement de la saison. */
    #[Route('/detenteurs', name: 'detenteurs', methods: ['GET'])]
    public function detenteurs(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $restored = $this->filterMemory->restoreOrRemember('cles', $request, ['statut', 'search']);
        if ($restored !== null) {
            return $this->redirectToRoute('admin_cles_detenteurs', $restored);
        }

        $search = trim((string) $request->query->get('search', ''));
        $statut = CleDetentionStatut::tryFrom((string) $request->query->get('statut', ''));

        $this->licenceSync->pourSaison($season);

        $lignes = $this->presenter->lignes($season);

        return $this->render('admin/cles/detenteurs.html.twig', [
            'season' => $season,
            'lignes' => $this->presenter->filtrer($lignes, $search, $statut),
            'candidats' => $this->presenter->candidats($season, $lignes),
            'search' => $search,
            'filterGroups' => [FiltreListe::depuisEnum(
                'statut',
                'Statut',
                'Tous',
                CleDetentionStatut::cases(),
                $statut,
            )],
            'activeFilterCount' => ($search !== '' ? 1 : 0) + ($statut !== null ? 1 : 0),
        ]);
    }

    /**
     * L'historique complet, paginé. Une restitution et une perte n'apparaissent nulle
     * part ailleurs : s'en tenir aux derniers en date les ferait disparaître.
     */
    #[Route('/mouvements', name: 'mouvements', methods: ['GET'])]
    public function mouvements(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));

        ['mouvements' => $mouvements, 'total' => $total] = $this->registre
            ->getMouvementsPage($page, self::MOUVEMENTS_PAR_PAGE);

        return $this->render('admin/cles/mouvements.html.twig', [
            'mouvements' => $mouvements,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::MOUVEMENTS_PAR_PAGE)),
        ]);
    }

    #[Route('/mouvement', name: 'mouvement', methods: ['POST'])]
    #[IsGranted(Permission::CLE_GERER->value)]
    public function mouvement(Request $request): Response
    {
        $this->csrf->valider('cle_mouvement', $request);

        $type = CleMouvementType::tryFrom((string) $request->request->get('type', ''));

        if ($type === null) {
            $this->addFlash('error', 'Mouvement invalide.');

            return $this->redirectToRoute('admin_cles_detenteurs');
        }

        $dateRaw = trim((string) $request->request->get('date_mouvement', ''));

        try {
            $detenteur = $this->detenteurService->resoudre((string) $request->request->get('personne', ''));

            $data = new CleMouvementData(
                type: $type,
                quantite: (int) $request->request->get('quantite', 1),
                dateMouvement: new \DateTimeImmutable($dateRaw !== '' ? $dateRaw : 'today'),
                note: trim((string) $request->request->get('note', '')) ?: null,
            );

            $user = $this->getUser();
            $this->registre->record($detenteur, $data, $user instanceof User ? $user : null);

            $this->addFlash('success', sprintf(
                '%s enregistrée pour %s.',
                $type->label(),
                $detenteur->getNomPrenom(),
            ));
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Exception) {
            $this->addFlash('error', 'Date de mouvement invalide.');
        }

        return $this->redirectToRoute('admin_cles_detenteurs');
    }

    /**
     * Correction de la fiche d'une personne **extérieure** au club. Sa raison d'être est la
     * **qualité** : c'est elle qui s'imprime en face d'un extérieur sur le récapitulatif
     * remis à la mairie, et rien ne permettait de la poser après la création — une fiche
     * entrée dans l'urgence restait « Détenteur extérieur au club » pour toujours.
     *
     * Un détenteur de l'effectif n'y entre pas : son identité appartient à sa fiche
     * dirigeant. Le service le refuse aussi de son côté — masquer n'est pas protéger.
     *
     * Un écran à part et non une modale de ligne : cinq champs d'identité ne tiennent pas
     * dans un panneau de menu.
     */
    #[Route('/detenteurs/{id}/modifier', name: 'detenteur_modifier', methods: ['GET', 'POST'], requirements: ['id' => '\\d+'])]
    #[IsGranted(Permission::CLE_GERER->value)]
    public function modifierDetenteur(int $id, Request $request): Response
    {
        $detenteur = $this->detenteurRepo->find($id);

        if ($detenteur === null) {
            $this->addFlash('error', 'Cette fiche du registre n\'existe plus.');

            return $this->redirectToRoute('admin_cles_detenteurs');
        }

        if ($this->effectifResolver->estRattacheALEffectif($detenteur)) {
            $this->addFlash('error', sprintf(
                '%s figure à l\'effectif du club : sa fiche se corrige depuis sa fiche de dirigeant. Seules les personnes extérieures se modifient ici.',
                $detenteur->getNomPrenom(),
            ));

            return $this->redirectToRoute('admin_cles_detenteurs');
        }

        if ($request->isMethod('POST')) {
            $this->csrf->valider('cle_detenteur_modifier_' . $detenteur->getId(), $request);

            try {
                $this->detenteurService->modifier(
                    detenteur: $detenteur,
                    nom: (string) $request->request->get('nom', ''),
                    prenom: (string) $request->request->get('prenom', ''),
                    qualite: trim((string) $request->request->get('qualite', '')) ?: null,
                    email: trim((string) $request->request->get('email', '')) ?: null,
                    telephone: trim((string) $request->request->get('telephone', '')) ?: null,
                );

                $this->addFlash('success', sprintf('Fiche de %s mise à jour.', $detenteur->getNomPrenom()));

                return $this->redirectToRoute('admin_cles_detenteurs');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/cles/detenteur_modifier.html.twig', [
            'detenteur' => $detenteur,
        ]);
    }

    #[Route('/detenteurs/exterieur', name: 'detenteur_exterieur', methods: ['POST'])]
    #[IsGranted(Permission::CLE_GERER->value)]
    public function detenteurExterieur(Request $request): Response
    {
        $this->csrf->valider('cle_detenteur_exterieur', $request);

        $nom = trim((string) $request->request->get('nom', ''));
        $prenom = trim((string) $request->request->get('prenom', ''));

        if ($nom === '' || $prenom === '') {
            $this->addFlash('error', 'Le nom et le prénom sont obligatoires.');

            return $this->redirectToRoute('admin_cles_detenteurs');
        }

        try {
            $detenteur = $this->detenteurService->creerExterieur(
                nom: $nom,
                prenom: $prenom,
                qualite: trim((string) $request->request->get('qualite', '')) ?: null,
                email: trim((string) $request->request->get('email', '')) ?: null,
                telephone: trim((string) $request->request->get('telephone', '')) ?: null,
            );

            $this->addFlash('success', sprintf('%s ajouté au registre : vous pouvez lui remettre une clé.', $detenteur->getNomPrenom()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_cles_detenteurs');
    }

    /** Envoi groupé du lien de signature — déclenché à la main, jamais automatiquement. */
    #[Route('/attestations/campagne', name: 'campagne', methods: ['POST'])]
    #[IsGranted(Permission::CLE_GERER->value)]
    public function campagne(Request $request, #[CurrentSeason] Season $season): Response
    {
        $this->csrf->valider('cle_campagne_' . $season->getId(), $request);

        $resultat = $this->attestations->lancerCampagne($season);

        if ($resultat->rienAFaire()) {
            $this->addFlash('success', sprintf('Tous les détenteurs ont signé pour la saison %s.', $season->getLabel()));

            return $this->redirectToRoute('admin_cles_index');
        }

        if ($resultat->envoyes > 0) {
            $this->addFlash('success', sprintf(
                '%d lien%s de signature envoyé%s pour la saison %s.',
                $resultat->envoyes,
                $resultat->envoyes > 1 ? 's' : '',
                $resultat->envoyes > 1 ? 's' : '',
                $season->getLabel(),
            ));
        }

        if ($resultat->sansEmail !== []) {
            $this->addFlash('error', sprintf(
                'Sans adresse mail, à faire signer sur place : %s.',
                implode(', ', $resultat->sansEmail),
            ));
        }

        if ($resultat->echecs !== []) {
            $this->addFlash('error', sprintf(
                'Envoi impossible pour : %s.',
                implode(', ', $resultat->echecs),
            ));
        }

        return $this->redirectToRoute('admin_cles_index');
    }

    #[Route('/detenteurs/{id}/attestation/demander', name: 'demander_signature', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::CLE_GERER->value)]
    public function demanderSignature(int $id, Request $request, #[CurrentSeason] Season $season): Response
    {
        $this->csrf->valider('cle_demander_signature_' . $id, $request);

        $detenteur = $this->detenteurRepo->find($id);

        if ($detenteur === null) {
            $this->addFlash('error', 'Détenteur introuvable.');

            return $this->redirectToRoute('admin_cles_detenteurs');
        }

        try {
            $this->attestations->demander($detenteur, $season);
            $this->addFlash('success', sprintf('Lien de signature envoyé à %s.', $detenteur->getEmail()));
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_cles_detenteurs');
    }
}
