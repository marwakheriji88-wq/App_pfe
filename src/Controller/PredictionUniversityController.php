<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PredictionUniversityController extends AbstractController
{
    private $httpClient;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    // Champs numériques attendus par l'API Flask (feature_num du notebook)
    private const NUMERIC_FIELDS = [
        'the_overall',
        'the_teaching',
        'the_research',
        'the_citations',
        'the_industry_income',
        'the_international_outlook',
        'n_students',
        'student_staff_ratio',
        'pc_intl_students',
        'qs_overall',
        'qs_employer_reputation',
        'qs_employment_outcomes',
        'arwu_score',
    ];

    #[Route('/predict/university-transition', name: 'predict_university_transition', methods: ['GET', 'POST'])]
    public function predict(Request $request): Response
    {
        $user = $this->getUser();

        $error = null;
        $prediction = null;
        $probabilities = null;
        $warning = null;

        // Liste des options pour le champ "cat_from"
        // (doit correspondre aux catégories vues à l'entraînement : Faible / Moyen / Bon / Tres bon / Elite)
        $catFromOptions = ['Faible', 'Moyen', 'Bon', 'Tres bon', 'Elite'];

        // Valeurs soumises (utilisées pour repré-remplir le formulaire en cas d'erreur)
        $formValues = [];
        foreach (self::NUMERIC_FIELDS as $field) {
            $formValues[$field] = '';
        }
        $formValues['cat_from'] = '';
        $formValues['country'] = '';

        if ($request->isMethod('POST')) {
            // Récupère les champs numériques + les deux champs catégoriels
            foreach (self::NUMERIC_FIELDS as $field) {
                $formValues[$field] = $request->request->get($field);
            }
            $formValues['cat_from'] = $request->request->get('cat_from');
            $formValues['country'] = $request->request->get('country');

            // Vérifie qu'aucun champ n'est vide
            $missing = [];
            foreach ($formValues as $field => $value) {
                if ($value === null || $value === '') {
                    $missing[] = $field;
                }
            }

            if (!empty($missing)) {
                $error = "Veuillez remplir tous les champs. Champs manquants : " . implode(', ', $missing);
            } else {
                try {
                    // Construit le payload JSON : cast des champs numériques en float
                    $payload = [];
                    foreach (self::NUMERIC_FIELDS as $field) {
                        $payload[$field] = (float) $formValues[$field];
                    }
                    $payload['cat_from'] = $formValues['cat_from'];
                    $payload['country'] = $formValues['country'];

                    // Appeler l'API Flask
                    $response = $this->httpClient->request('POST', 'http://127.0.0.1:5000/predict/university-transition', [
                        'json' => $payload,
                    ]);

                    $data = $response->toArray(false);

                    if (isset($data['error'])) {
                        $error = $data['error'];
                        if (isset($data['missing_fields'])) {
                            $error .= ' (' . implode(', ', $data['missing_fields']) . ')';
                        }
                    } else {
                        $prediction = $data['prediction'];
                        $probabilities = $data['probabilities'] ?? null;
                        $warning = $data['warning'] ?? null;
                    }
                } catch (\Exception $e) {
                    $error = "Erreur lors de la communication avec l'API Flask : " . $e->getMessage();
                }
            }
        }

        // Rendre la vue Twig
        return $this->render('prediction_university/index.html.twig', [
            'error' => $error,
            'username' => $user->getUsername(),
            'prediction' => $prediction,
            'probabilities' => $probabilities,
            'warning' => $warning,
            'cat_from_options' => $catFromOptions,
            'numeric_fields' => self::NUMERIC_FIELDS,
            'form_values' => $formValues,
        ]);
    }
}