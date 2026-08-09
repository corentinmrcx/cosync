<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\CsrfGuard;
use App\Security\Voter\SuperAdminVoter;
use App\Service\BetaModeService;
use App\Service\Mail\MailerService;
use App\Service\PurgeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/diagnostic', name: 'admin_diag_')]
#[IsGranted(SuperAdminVoter::ACCES_DIAGNOSTIC)]
class DiagController extends AbstractController
{
    public function __construct(
        private readonly MailerService $mailerService,
        private readonly CsrfGuard $csrf,
        private readonly BetaModeService $betaModeService,
        private readonly PurgeService $purgeService,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/diag/index.html.twig', [
            'betaActive' => $this->betaModeService->isActive(),
            'diagEmail' => $this->betaModeService->getRedirectEmail(),
        ]);
    }

    #[Route('/test-mail', name: 'test_mail', methods: ['POST'])]
    public function testMail(Request $request): Response
    {
        $this->csrf->valider('test_mail', $request);

        $to = filter_var(trim((string) $request->request->get('test_email', '')), FILTER_VALIDATE_EMAIL);

        if ($to === false) {
            $this->addFlash('error', 'Adresse email invalide.');

            return $this->redirectToRoute('admin_diag_index');
        }

        try {
            $this->mailerService->sendTestEmail($to);
            $this->addFlash('success', sprintf('Mail de test envoyé à %s.', $to));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Échec d\'envoi : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_diag_index');
    }

    #[Route('/test-validation-mail', name: 'test_validation_mail', methods: ['POST'])]
    public function testValidationMail(Request $request): Response
    {
        $this->csrf->valider('test_validation_mail', $request);

        $to = filter_var(trim((string) $request->request->get('test_email', '')), FILTER_VALIDATE_EMAIL);

        if ($to === false) {
            $this->addFlash('error', 'Adresse email invalide.');

            return $this->redirectToRoute('admin_diag_index');
        }

        $isJeune = $request->request->get('profil') === 'jeune';

        try {
            $this->mailerService->sendValidationTest($to, $isJeune);
            $profil = $isJeune ? 'jeune (Thomas DUPONT)' : 'senior (Kévin MARTIN)';
            $this->addFlash('success', sprintf('Mail de validation envoyé à %s (profil %s).', $to, $profil));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Échec d\'envoi : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_diag_index');
    }

    #[Route('/beta/activer', name: 'beta_enable', methods: ['POST'])]
    public function enableBeta(Request $request): Response
    {
        $this->csrf->valider('beta_enable', $request);

        $this->betaModeService->enable();
        $this->addFlash('success', 'Mode beta activé. Chaque mail sera redirigé vers la boite de l\'utilisateur connecté au moment de l\'action.');

        return $this->redirectToRoute('admin_diag_index');
    }

    #[Route('/beta/desactiver', name: 'beta_disable', methods: ['POST'])]
    public function disableBeta(Request $request): Response
    {
        $this->csrf->valider('beta_disable', $request);

        $this->betaModeService->disable();
        $this->addFlash('success', 'Mode beta désactivé. Les mails partent désormais vers les vrais destinataires.');

        return $this->redirectToRoute('admin_diag_index');
    }

    #[Route('/purge', name: 'purge', methods: ['POST'])]
    public function purge(Request $request): Response
    {
        if (!$this->betaModeService->isActive()) {
            $this->addFlash('error', 'La purge n\'est disponible qu\'en mode beta.');

            return $this->redirectToRoute('admin_diag_index');
        }

        $this->csrf->valider('purge', $request);

        if ($request->request->get('confirmation') !== 'SUPPRIMER') {
            $this->addFlash('error', 'Confirmation incorrecte. Aucune donnée supprimée.');

            return $this->redirectToRoute('admin_diag_index');
        }

        try {
            $counts = $this->purgeService->purgeAll();
            $total = array_sum($counts);
            $this->addFlash('success', sprintf('%d enregistrements supprimés. La base est vide et prête pour de nouvelles données de test.', $total));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur lors de la purge : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_diag_index');
    }
}
