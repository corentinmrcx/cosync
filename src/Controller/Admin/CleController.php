<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\CleMouvementData;
use App\Entity\User;
use App\Enum\CleMouvementType;
use App\Repository\DirigeantRepository;
use App\Service\ClubHouse\CleRegistreService;
use App\Service\ListFilterMemory;
use App\Service\Mail\AttestationCleLinkService;
use App\Service\SeasonContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/club-house/cles', name: 'admin_clubhouse_cles_')]
class CleController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        SeasonContext $seasonContext,
        CleRegistreService $registre,
        DirigeantRepository $dirigeantRepo,
        ListFilterMemory $filterMemory,
    ): Response {
        $season = $seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $restored = $filterMemory->restoreOrRemember('clubhouse_cles', $request, ['statut', 'search']);
        if ($restored !== null) {
            return $this->redirectToRoute('admin_clubhouse_cles_index', $restored);
        }

        $search = trim((string) $request->query->get('search', ''));
        $statut = (string) $request->query->get('statut', '');

        $detentions = $registre->getDetentions($season);

        return $this->render('admin/clubhouse/cles.html.twig', [
            'season'       => $season,
            'stats'        => $registre->getStats($season),
            'detentions'   => $this->filterDetentions($detentions, $search, $statut),
            'candidats'    => $dirigeantRepo->findBySeason($season),
            'search'       => $search,
            'filterGroups' => [[
                'name'     => 'statut',
                'label'    => 'Statut',
                'allLabel' => 'Tous',
                'options'  => [
                    ['value' => 'detenteur', 'label' => 'Détenteurs actuels'],
                    ['value' => 'signature_manquante', 'label' => 'Attestation non signée'],
                    ['value' => 'restitue', 'label' => 'Clés restituées'],
                ],
                'current'  => $statut !== '' ? $statut : null,
            ]],
            'activeFilterCount' => ($search !== '' ? 1 : 0) + ($statut !== '' ? 1 : 0),
        ]);
    }

    #[Route('/mouvement', name: 'mouvement', methods: ['POST'])]
    public function mouvement(
        Request $request,
        DirigeantRepository $dirigeantRepo,
        CleRegistreService $registre,
    ): Response {
        if (!$this->isCsrfTokenValid('cle_mouvement', $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');
            return $this->redirectToRoute('admin_clubhouse_cles_index');
        }

        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString((string) $request->request->get('dirigeant', '')));
        $type      = CleMouvementType::tryFrom((string) $request->request->get('type', ''));

        if ($dirigeant === null || $type === null) {
            $this->addFlash('error', 'Mouvement invalide.');
            return $this->redirectToRoute('admin_clubhouse_cles_index');
        }

        $dateRaw = trim((string) $request->request->get('date_mouvement', ''));

        try {
            $data = new CleMouvementData(
                type: $type,
                quantite: (int) $request->request->get('quantite', 1),
                dateMouvement: new \DateTimeImmutable($dateRaw !== '' ? $dateRaw : 'today'),
                note: trim((string) $request->request->get('note', '')) ?: null,
            );

            $user = $this->getUser();
            $registre->record($dirigeant, $data, $user instanceof User ? $user : null);

            $this->addFlash('success', sprintf(
                '%s enregistrée pour %s.',
                $type->label(),
                $dirigeant->getNomPrenom(),
            ));
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Exception) {
            $this->addFlash('error', 'Date de mouvement invalide.');
        }

        return $this->redirectToRoute('admin_clubhouse_cles_index');
    }

    #[Route('/{uuid}/attestation/envoyer-lien', name: 'send_link', methods: ['POST'], requirements: ['uuid' => Requirement::UUID])]
    public function sendAttestationCleLink(
        string $uuid,
        Request $request,
        DirigeantRepository $dirigeantRepo,
        AttestationCleLinkService $linkService,
    ): Response {
        if (!$this->isCsrfTokenValid('attestation_cle_send_link_' . $uuid, $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');
            return $this->redirectToRoute('admin_clubhouse_cles_index');
        }

        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null) {
            $this->addFlash('error', 'Dirigeant introuvable.');
            return $this->redirectToRoute('admin_clubhouse_cles_index');
        }

        try {
            $linkService->send($dirigeant);
            $this->addFlash('success', sprintf('Lien de signature envoyé à %s.', $dirigeant->getEmail()));
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_clubhouse_cles_index');
    }

    /**
     * @param \App\DTO\CleDetention[] $detentions
     *
     * @return \App\DTO\CleDetention[]
     */
    private function filterDetentions(array $detentions, string $search, string $statut): array
    {
        return array_values(array_filter($detentions, static function ($detention) use ($search, $statut): bool {
            if ($search !== '') {
                $haystack = mb_strtolower($detention->dirigeant->getNomPrenom());
                if (!str_contains($haystack, mb_strtolower($search))) {
                    return false;
                }
            }

            return match ($statut) {
                'detenteur'           => $detention->estDetenteur(),
                'signature_manquante' => $detention->estDetenteur() && !$detention->dirigeant->hasSignedAttestationCle(),
                'restitue'            => !$detention->estDetenteur(),
                default               => true,
            };
        }));
    }
}
