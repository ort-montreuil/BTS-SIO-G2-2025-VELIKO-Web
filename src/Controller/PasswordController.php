<?php

namespace App\Controller;

use App\Form\ChangePasswordFormType;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Services\Token;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;


class PasswordController extends AbstractController
{
    #[Route('/envoieMailMdp', name: 'app_emailMdp' )]
    public function envoyer(
        Token                  $tokenService,
        MailerInterface        $mailer,
        EntityManagerInterface $entityManager,
        Security               $security,
        SessionInterface       $session,
        userRepository         $userRepository,
        request                $request
    ): Response {

        $user = $security->getUser();

        if (!$user) {
            $this->addFlash('error', 'Vous devez être connecté pour effectuer cette action.');
            return $this->redirectToRoute('app_login');
        }

        // Génère le token et l'associe à l'utilisateur
        $confirmationToken = $tokenService->generateToken();
        $user->setConfirmationToken($confirmationToken);
        $entityManager->persist($user);
        $entityManager->flush();

        // Crée le lien de confirmation pour la réinitialisation de mot de passe
        $confirmationUrl = $this->generateUrl(
            'app_confirm_email_pwd',
            ['token' => $confirmationToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Envoie l'e-mail de confirmation
        $confirmationEmail = (new Email())
            ->from('noreply@yourdomain.com')
            ->to($user->getEmail())
            ->subject('Confirmation de réinitialisation de mot de passe')
            ->html("<p>Veuillez cliquer sur le lien pour confirmer votre demande de réinitialisation de mot de passe : <a href='{$confirmationUrl}'>Confirmer la réinitialisation</a></p>");

        $mailer->send($confirmationEmail);

        $this->addFlash('success', 'Un e-mail de confirmation a été envoyé. Veuillez vérifier votre boîte de réception.');
        return $this->redirectToRoute('app_profile');
    }
    #[Route('/reset-password', name: 'app_request_reset_password', methods: ['GET', 'POST'])]
    public function requestResetPassword(Request $request, UserRepository $userRepository, Token $tokenService, MailerInterface $mailer, EntityManagerInterface $entityManager): Response {
        if ($request->isMethod('POST')) {
            $email = $request->request->get('_username');
            if (!$email) {
                $this->addFlash('error', 'Veuillez entrer un email valide.');
                return $this->redirectToRoute('app_request_reset_password');
            }

            $user = $userRepository->findOneBy(['email' => $email]);
            if (!$user) {
                $this->addFlash('error', 'Aucun utilisateur trouvé avec cet e-mail.');
                return $this->redirectToRoute('app_request_reset_password');
            }

            // Génère le token et l'associe à l'utilisateur
            $confirmationToken = $tokenService->generateToken();
            $user->setConfirmationToken($confirmationToken);
            $entityManager->persist($user);
            $entityManager->flush();

            // Crée le lien de confirmation pour la réinitialisation de mot de passe
            $confirmationUrl = $this->generateUrl(
                'app_confirm_email_pwd',
                ['token' => $confirmationToken],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Envoie l'e-mail de confirmation
            $confirmationEmail = (new Email())
                ->from('noreply@yourdomain.com')
                ->to($user->getEmail())
                ->subject('Confirmation de réinitialisation de mot de passe')
                ->html("<p>Veuillez cliquer sur le lien pour confirmer votre demande de réinitialisation de mot de passe : <a href='{$confirmationUrl}'>Confirmer la réinitialisation</a></p>");

            $mailer->send($confirmationEmail);

            $this->addFlash('success', 'Un e-mail de confirmation a été envoyé. Veuillez vérifier votre boîte de réception.');
            return $this->redirectToRoute('app_login');
        }
        return $this->render('/reset_password_request/index.html.twig');
    }
    #[Route('/confirm-reset-password/{token}', name: 'app_confirm_email_pwd')]
    public function confirmResetPassword(
        string $token,
        UserRepository $userRepository,
        SessionInterface $session
    ): Response {
        // Recherche l'utilisateur avec ce token
        $user = $userRepository->findOneBy(['confirmationToken' => $token]);

        if (!$user) {
            $this->addFlash('error', 'Le lien de réinitialisation est invalide ou expiré.');
            return $this->redirectToRoute('app_profile');
        }

        // Stocke temporairement l'ID de l'utilisateur pour la prochaine étape
        $session->set('reset_password_user_id', $user->getId());

        // Redirige vers la page de changement de mot de passe
        return $this->redirectToRoute('app_change_password');
    }
    #[Route('/change-password', name: 'app_change_password')]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        SessionInterface $session
    ): Response {
        // Récupère l'ID de l'utilisateur à partir de la session
        $userId = $session->get('reset_password_user_id');
        if (!$userId) {
            $this->addFlash('error', 'Une erreur est survenue. Veuillez recommencer le processus.');
            return $this->redirectToRoute('app_profile');
        }

        // Récupère l'utilisateur par son ID
        $user = $entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            $this->addFlash('error', 'Utilisateur non trouvé.');
            return $this->redirectToRoute('app_profile');
        }

        // Crée le formulaire pour changer le mot de passe
        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Récupère et vérifie les mots de passe
            $plainPassword = $form->get('plainPassword')->getData();
            $confirmPassword = $form->get('confirmPassword')->getData();

            if ($plainPassword !== $confirmPassword) {
                $this->addFlash('error', 'Les mots de passe ne correspondent pas.');
                return $this->render('password/index.html.twig', [
                    'changePasswordForm' => $form->createView(),
                ]);
            }

            // Met à jour le mot de passe et réinitialise le token
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            $user->setConfirmationToken(null);
            $entityManager->persist($user);
            $entityManager->flush();

            // Nettoie la session
            $session->remove('reset_password_user_id');

            $this->addFlash('success', 'Votre mot de passe a été modifié avec succès.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('password/index.html.twig', [
            'changePasswordForm' => $form->createView(),
        ]);
    }
}