<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\CleMouvementData;
use App\DTO\FiltreListe;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CleDetentionStatut;
use App\Enum\CleMouvementType;
use App\Repository\DirigeantRepository;
use App\Security\CsrfGuard;
use App\Service\ClubHouse\CleRegistreService;
use App\Service\Mail\AttestationCleLinkService;
use App\Service\Ui\ListFilterMemory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/club-house/cles', name: 'admin_clubhouse_cles_')]
class CleController extends AbstractController
{
    public function __construct(
        private readonly CleRegistreService $registre,
        private readonly DirigeantRepository $dirigeantRepo,
        private readonly ListFilterMemory $filterMemory,
        private readonly AttestationCleLinkService $linkService,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $restored = $this->filterMemory->restoreOrRemember('clubhouse_cles', $request, ['statut', 'search']);
        if ($restored !== null) {
            return $this->redirectToRoute('admin_clubhouse_cles_index', $restored);
        }

        $search = trim((string) $request->query->get('search', ''));
        $statut = CleDetentionStatut::tryFrom((string) $request->query->get('statut', ''));

        return $this->render('admin/clubhouse/cles.html.twig', [
            'season' => $season,
            'stats' => $this->registre->getStats($season),
            'detentions' => $this->registre->rechercherDetentions($season, $search, $statut),
            'candidats' => $this->dirigeantRepo->findBySeason($season),
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

    #[Route('/mouvement', name: 'mouvement', methods: ['POST'])]
    public function mouvement(
        Request $request,
    ): Response {
        $this->csrf->valider('cle_mouvement', $request);

        $dirigeant = $this->dirigeantRepo->findByUuid(Uuid::fromString((string) $request->request->get('dirigeant', '')));
        $type = CleMouvementType::tryFrom((string) $request->request->get('type', ''));

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
            $this->registre->record($dirigeant, $data, $user instanceof User ? $user : null);

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
    ): Response {
        $this->csrf->valider('attestation_cle_send_link_' . $uuid, $request);

        $dirigeant = $this->dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null) {
            $this->addFlash('error', 'Dirigeant introuvable.');

            return $this->redirectToRoute('admin_clubhouse_cles_index');
        }

        try {
            $this->linkService->send($dirigeant);
            $this->addFlash('success', sprintf('Lien de signature envoyé à %s.', $dirigeant->getEmail()));
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_clubhouse_cles_index');
    }
}
