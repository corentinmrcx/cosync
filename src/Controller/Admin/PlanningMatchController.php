<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\Planning\PlanningContactsData;
use App\DTO\Planning\PlanningMatchData;
use App\Entity\MatchDomicile;
use App\Entity\Season;
use App\Enum\Permission;
use App\Enum\PlanningFormat;
use App\Exception\FffApiException;
use App\Form\PlanningMatchType;
use App\Security\CsrfGuard;
use App\Service\Drive\PlanningDriveSync;
use App\Service\Pdf\PlanningPdfService;
use App\Service\Planning\Fff\FffSyncService;
use App\Service\Planning\Import\CollagePlanningParser;
use App\Service\Planning\PlanningDocumentPresenter;
use App\Service\Planning\PlanningMatchService;
use App\Service\Planning\PlanningPeriodeResolver;
use App\Service\Referentiel\ClubSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Planning des matchs à domicile : saisie, synchronisation FFF, impression.
 *
 * Le hub est au niveau du club, mais un planning appartient à une saison : d'où
 * `#[CurrentSeason]`, qui rend la saison de travail de l'admin (et retombe sur la plus
 * récente hors session).
 */
#[Route('/admin/outils/planning-matchs', name: 'admin_planning_')]
#[IsGranted(Permission::PLANNING_LIRE->value)]
class PlanningMatchController extends AbstractController
{
    public function __construct(
        private readonly PlanningMatchService $matchService,
        private readonly PlanningPeriodeResolver $periodes,
        private readonly PlanningDocumentPresenter $presenter,
        private readonly FffSyncService $syncService,
        private readonly CollagePlanningParser $parser,
        private readonly PlanningPdfService $pdf,
        private readonly PlanningDriveSync $driveSync,
        private readonly ClubSettingsService $clubSettings,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[CurrentSeason] Season $season): Response
    {
        return $this->render('admin/outils/planning/index.html.twig', [
            'season' => $season,
            'matchs' => $this->matchService->listerPourAdmin($season),
            'form' => $this->createForm(PlanningMatchType::class, new PlanningMatchData(), [
                'action' => $this->generateUrl('admin_planning_new'),
            ]),
            'club' => $this->clubSettings->get(),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function new(#[CurrentSeason] Season $season, Request $request): Response
    {
        $form = $this->createForm(PlanningMatchType::class, $data = new PlanningMatchData());
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // On rend les erreurs réelles du formulaire. Un message générique annonçait
            // « la date et la catégorie sont obligatoires » alors que c'était l'heure qui
            // était refusée : l'admin corrigeait deux champs déjà bons.
            foreach ($form->getErrors(true) as $erreur) {
                $this->addFlash('error', $erreur->getMessage());
            }

            return $this->redirectToRoute('admin_planning_index');
        }

        try {
            $this->matchService->creer($data, $season);
            $this->addFlash('success', 'Match ajouté au planning.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_planning_index');
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function edit(MatchDomicile $match, Request $request): Response
    {
        if (!$match->estModifiable()) {
            $this->addFlash('error', 'Ce match suit le calendrier FFF. Détachez-le d\'abord si vous devez corriger son horaire.');

            return $this->redirectToRoute('admin_planning_index');
        }

        $form = $this->createForm(PlanningMatchType::class, $data = PlanningMatchData::depuis(
            $match->getDate(),
            $match->getHeure(),
            $match->getCategorie(),
            $match->getAdversaire(),
            $match->getNote(),
        ));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->matchService->modifier($match, $data);
                $this->addFlash('success', 'Match mis à jour.');

                return $this->redirectToRoute('admin_planning_index');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/outils/planning/edit.html.twig', [
            'form' => $form,
            'match' => $match,
        ]);
    }

    /** La note se change sur toutes les lignes, fédérales comprises : c'est la part du club. */
    #[Route('/{id}/note', name: 'note', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function note(MatchDomicile $match, Request $request): Response
    {
        $this->csrf->valider('planning_note_' . $match->getId(), $request);

        $this->matchService->changerNote($match, (string) $request->request->get('note'));
        $this->addFlash('success', 'Note enregistrée.');

        return $this->redirectToRoute('admin_planning_index');
    }

    #[Route('/{id}/masquer', name: 'toggle_masque', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function toggleMasque(MatchDomicile $match, Request $request): Response
    {
        $this->csrf->valider('planning_masque_' . $match->getId(), $request);

        $this->matchService->basculerMasque($match);
        $this->addFlash('success', $match->isMasque()
            ? 'Match retiré des documents imprimés. Il reste dans la liste.'
            : 'Match réintégré aux documents imprimés.');

        return $this->redirectToRoute('admin_planning_index');
    }

    #[Route('/{id}/detacher', name: 'detach', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function detach(MatchDomicile $match, Request $request): Response
    {
        $this->csrf->valider('planning_detach_' . $match->getId(), $request);

        $this->matchService->detacher($match);
        $this->addFlash('success', 'Match détaché du calendrier FFF : il est modifiable, et la synchronisation ne le touchera plus.');

        return $this->redirectToRoute('admin_planning_index');
    }

    #[Route('/{id}/reprendre-fff', name: 'reattach', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function reattach(MatchDomicile $match, Request $request): Response
    {
        $this->csrf->valider('planning_reattach_' . $match->getId(), $request);

        $this->matchService->reprendreLaFff($match);
        $this->addFlash('success', 'Match rendu au calendrier FFF : la prochaine synchronisation reprendra son horaire officiel.');

        return $this->redirectToRoute('admin_planning_index');
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function delete(MatchDomicile $match, Request $request): Response
    {
        $this->csrf->valider('planning_delete_' . $match->getId(), $request);

        // Un match fédéral supprimé reviendrait à la synchronisation suivante : le masque
        // est la seule façon de l'écarter durablement. On le dit plutôt que de laisser
        // l'admin recommencer trois fois.
        if ($match->suitLaFff()) {
            $this->addFlash('error', 'Ce match vient de la FFF : le supprimer ne servirait à rien, la prochaine synchronisation le recréerait. Masquez-le pour le retirer des documents.');

            return $this->redirectToRoute('admin_planning_index');
        }

        $this->matchService->supprimer($match);
        $this->addFlash('success', 'Match supprimé.');

        return $this->redirectToRoute('admin_planning_index');
    }

    /* ── Calendrier FFF ── */

    #[Route('/reglages', name: 'settings', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function settings(Request $request): Response
    {
        $settings = $this->clubSettings->get();
        $verification = null;
        $erreurVerification = null;

        if ($request->isMethod('POST')) {
            $this->csrf->valider('planning_reglages', $request);

            $brut = trim((string) $request->request->get('fffClubNo'));
            $clubNo = $brut === '' ? null : (int) $brut;

            // On tente de vérifier — un numéro faux ramènerait le calendrier d'un autre
            // club sans que rien ne le signale — mais on **enregistre quand même**. Le
            // service fédéral refuse les appels venant de certains hébergements : bloquer
            // l'enregistrement sur une vérification impossible laisserait l'admin
            // incapable de renseigner quoi que ce soit, pour une raison qui ne le regarde pas.
            if ($clubNo !== null) {
                try {
                    $verification = $this->syncService->verifierClub($clubNo);
                } catch (FffApiException $e) {
                    $erreurVerification = $e->messageAdmin();
                }
            }

            $settings->setFffClubNo($clubNo);
            $settings->setFffSyncActive($clubNo !== null && $request->request->getBoolean('fffSyncActive'));
            $settings->setPlanningContacts(PlanningContactsData::fromRequest($request)->versTexte());
            $this->clubSettings->enregistrer();

            if ($clubNo === null) {
                $this->addFlash('success', 'Réglages enregistrés. Le planning se remplit à la main.');
            } elseif ($verification !== null) {
                $this->addFlash('success', sprintf('Réglages enregistrés — club reconnu : %s.', $verification['nom']));
            } else {
                $this->addFlash('success', 'Réglages enregistrés. Le numéro du club n\'a pas pu être vérifié (voir ci-dessous).');
            }
        }

        return $this->render('admin/outils/planning/reglages.html.twig', [
            'club' => $settings,
            'contacts' => PlanningContactsData::depuisTexte($settings->getPlanningContacts()),
            'verification' => $verification,
            'erreurVerification' => $erreurVerification,
        ]);
    }

    #[Route('/synchroniser', name: 'sync', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function sync(#[CurrentSeason] Season $season, Request $request): Response
    {
        $this->csrf->valider('planning_sync', $request);

        $resultat = $this->syncService->synchroniser($season);

        $this->addFlash($resultat->reussie ? 'success' : 'error', $resultat->resume());

        // Les lignes disparues du flux mais annotées demandent une décision humaine :
        // les taire les laisserait sur un planning qui n'a plus lieu d'être.
        if ($resultat->aVerifier !== []) {
            $this->addFlash('error', sprintf(
                'À vérifier — ces matchs ont disparu du calendrier FFF mais portent une note ou un masque, ils ont donc été conservés : %s.',
                implode(' ; ', $resultat->aVerifier),
            ));
        }

        return $this->redirectToRoute('admin_planning_index');
    }

    /* ── Import par collage ── */

    #[Route('/coller', name: 'paste', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function paste(#[CurrentSeason] Season $season, Request $request): Response
    {
        $texte = (string) $request->request->get('texte', '');
        $apercu = null;

        if ($request->isMethod('POST')) {
            $this->csrf->valider('planning_collage', $request);

            $annee = (int) explode('-', $season->getLabel())[0];
            $apercu = $this->parser->analyser($texte, $annee);

            // Deux boutons, deux temps : « Analyser » montre, « Enregistrer » écrit. Rien
            // n'entre en base sans que l'admin ait vu ce qui a été compris.
            if ($request->request->get('action') === 'enregistrer' && !$apercu->estVide()) {
                $nombre = $this->matchService->importerLot($apercu->matchs, $season);
                $this->addFlash('success', sprintf('%d match%s ajouté%s au planning.', $nombre, $nombre > 1 ? 's' : '', $nombre > 1 ? 's' : ''));

                return $this->redirectToRoute('admin_planning_index');
            }
        }

        return $this->render('admin/outils/planning/coller.html.twig', [
            'season' => $season,
            'texte' => $texte,
            'apercu' => $apercu,
        ]);
    }

    /* ── Génération des documents ── */

    #[Route('/generer', name: 'generate', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function generate(#[CurrentSeason] Season $season, Request $request): Response
    {
        $defaut = $this->periodes->parDefaut();

        if (!$request->isMethod('POST')) {
            return $this->render('admin/outils/planning/generer.html.twig', [
                'season' => $season,
                'periode' => $defaut,
                'nombre' => $this->presenter->compter($season, $defaut),
                'formats' => PlanningFormat::cases(),
            ]);
        }

        $this->csrf->valider('planning_generer', $request);

        try {
            $periode = $this->periodes->depuis(
                $this->lireDate($request->request->get('du')),
                $this->lireDate($request->request->get('au')),
            );
            $format = PlanningFormat::from((string) $request->request->get('format'));
        } catch (\DomainException|\ValueError $e) {
            $this->addFlash('error', $e instanceof \DomainException ? $e->getMessage() : 'Format d\'impression inconnu.');

            return $this->redirectToRoute('admin_planning_generate');
        }

        $contenu = $this->pdf->rendu($season, $periode, $format);
        $nomFichier = $this->pdf->nomFichier($season, $periode, $format);

        if ($request->request->getBoolean('archiver')) {
            $archive = $this->driveSync->archiver($contenu, $nomFichier, $season, $periode, $format);

            // L'échec est dit : le document est bien produit, mais croire qu'il est
            // archivé alors qu'il ne l'est pas est pire que de ne pas l'archiver.
            $this->addFlash($archive ? 'success' : 'error', $archive
                ? 'Planning archivé sur le Drive du club.'
                : 'Planning généré, mais l\'archivage Drive a échoué. Réessayez depuis cet écran.');
        }

        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $nomFichier),
        ]);
    }

    /** Aperçu du nombre de matchs d'une période, sans produire de document. */
    #[Route('/compter', name: 'count', methods: ['POST'])]
    #[IsGranted(Permission::PLANNING_GERER->value)]
    public function count(#[CurrentSeason] Season $season, Request $request): Response
    {
        $this->csrf->valider('planning_generer', $request);

        try {
            $periode = $this->periodes->depuis(
                $this->lireDate($request->request->get('du')),
                $this->lireDate($request->request->get('au')),
            );
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_planning_generate');
        }

        return $this->render('admin/outils/planning/generer.html.twig', [
            'season' => $season,
            'periode' => $periode,
            'nombre' => $this->presenter->compter($season, $periode),
            'formats' => PlanningFormat::cases(),
        ]);
    }

    private function lireDate(mixed $valeur): ?\DateTimeImmutable
    {
        if (!is_string($valeur) || $valeur === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $valeur);

        return $date === false ? null : $date->setTime(0, 0);
    }
}
