<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Mail\MailerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/diagnostic', name: 'admin_diag_')]
class DiagController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/diag/index.html.twig');
    }

    #[Route('/test-mail', name: 'test_mail', methods: ['POST'])]
    public function testMail(Request $request, MailerService $mailerService): Response
    {
        if (!$this->isCsrfTokenValid('test_mail', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_diag_index');
        }

        $to = filter_var(trim((string) $request->request->get('test_email', '')), FILTER_VALIDATE_EMAIL);

        if ($to === false) {
            $this->addFlash('error', 'Adresse email invalide.');
            return $this->redirectToRoute('admin_diag_index');
        }

        try {
            $mailerService->sendTestEmail($to);
            $this->addFlash('success', sprintf('Mail de test envoyé à %s.', $to));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Échec d\'envoi : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_diag_index');
    }

    #[Route('/test-validation-mail', name: 'test_validation_mail', methods: ['POST'])]
    public function testValidationMail(Request $request, MailerService $mailerService): Response
    {
        if (!$this->isCsrfTokenValid('test_validation_mail', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_diag_index');
        }

        $to = filter_var(trim((string) $request->request->get('test_email', '')), FILTER_VALIDATE_EMAIL);

        if ($to === false) {
            $this->addFlash('error', 'Adresse email invalide.');
            return $this->redirectToRoute('admin_diag_index');
        }

        $isJeune = $request->request->get('profil') === 'jeune';

        try {
            $mailerService->sendValidationTest($to, $isJeune);
            $profil = $isJeune ? 'jeune (Thomas DUPONT)' : 'senior (Kévin MARTIN)';
            $this->addFlash('success', sprintf('Mail de validation envoyé à %s (profil %s).', $to, $profil));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Échec d\'envoi : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_diag_index');
    }
}
