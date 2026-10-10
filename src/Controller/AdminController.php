<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted("IS_AUTHENTICATED_FULLY");
        $user = $this->getUser();

        return match ($user->isVerified()) {
            true => $this->render('admin/index.html.twig', ['username' => $user->getUsername()]),
            false => $this->render("admin/please-verify-email.html.twig"),
        };
    }

    #[Route('/search-highlight', name: 'search_highlight')]
    public function searchAndHighlight(Request $request, EntityManagerInterface $entityManager): Response
    {
        $query = $request->query->get('query');

        if (!$query) {
            return $this->render('admin/index.html.twig', [
                'results' => [],
                'query' => $query,
            ]);
        }

        // Exemple : Rechercher dans une table (ajustez selon vos entités)
        $results = $entityManager->getRepository(YourEntity::class)->createQueryBuilder('e')
            ->where('e.content LIKE :query') // Rechercher dans le champ `content`
            ->setParameter('query', '%' . $query . '%')
            ->getQuery()
            ->getResult();

        return $this->render('admin/index.html.twig', [
            'results' => $results,
            'query' => $query,
        ]);
    }


}
