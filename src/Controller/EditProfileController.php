<?php

namespace App\Controller;

use App\Form\ChangePasswordFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Form\EditProfileFormType;


class EditProfileController extends AbstractController
{
    #[Route('/edit/profile1', name: 'app_edit_profile')]
    public function index(): Response
    {
        return $this->render('edit_profile/index.html.twig', [
            'controller_name' => 'EditProfileController',
        ]);
    }

    #[Route('/edit/profile', name: 'edit_profile')]
    public function edit(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        $countries = [
            "Afghanistan", "Albania", "Algeria", "Andorra", "Angola", "Antigua and Barbuda",
            "Argentina", "Armenia", "Australia", "Austria", "Azerbaijan", "Bahamas", "Bahrain",
            "Bangladesh", "Barbados", "Belarus", "Belgium", "Belize", "Benin", "Bhutan",
            "Bolivia", "Bosnia and Herzegovina", "Botswana", "Brazil", "Brunei", "Bulgaria",
            "Burkina Faso", "Burundi", "Cabo Verde", "Cambodia", "Cameroon", "Canada",
            "Central African Republic", "Chad", "Chile", "China", "Colombia", "Comoros",
            "Congo (Congo-Brazzaville)", "Costa Rica", "Croatia", "Cuba", "Cyprus", "Czechia",
            "Denmark", "Djibouti", "Dominica", "Dominican Republic", "Ecuador", "Egypt",
            "El Salvador", "Equatorial Guinea", "Eritrea", "Estonia", "Eswatini (fmr. Swaziland)",
            "Ethiopia", "Fiji", "Finland", "France", "Gabon", "Gambia", "Georgia", "Germany",
            "Ghana", "Greece", "Grenada", "Guatemala", "Guinea", "Guinea-Bissau", "Guyana",
            "Haiti", "Holy See", "Honduras", "Hungary", "Iceland", "India", "Indonesia", "Iran",
            "Iraq", "Ireland", "Israel", "Italy", "Jamaica", "Japan", "Jordan", "Kazakhstan",
            "Kenya", "Kiribati", "Kuwait", "Kyrgyzstan", "Laos", "Latvia", "Lebanon", "Lesotho",
            "Liberia", "Libya", "Liechtenstein", "Lithuania", "Luxembourg", "Madagascar",
            "Malawi", "Malaysia", "Maldives", "Mali", "Malta", "Marshall Islands", "Mauritania",
            "Mauritius", "Mexico", "Micronesia", "Moldova", "Monaco", "Mongolia", "Montenegro",
            "Morocco", "Mozambique", "Myanmar (formerly Burma)", "Namibia", "Nauru", "Nepal",
            "Netherlands", "New Zealand", "Nicaragua", "Niger", "Nigeria", "North Korea",
            "North Macedonia", "Norway", "Oman", "Pakistan", "Palau", "Palestine State", "Panama",
            "Papua New Guinea", "Paraguay", "Peru", "Philippines", "Poland", "Portugal", "Qatar",
            "Romania", "Russia", "Rwanda", "Saint Kitts and Nevis", "Saint Lucia", "Saint Vincent
    and the Grenadines", "Samoa", "San Marino", "Sao Tome and Principe", "Saudi Arabia",
            "Senegal", "Serbia", "Seychelles", "Sierra Leone", "Singapore", "Slovakia", "Slovenia",
            "Solomon Islands", "Somalia", "South Africa", "South Korea", "South Sudan", "Spain",
            "Sri Lanka", "Sudan", "Suriname", "Sweden", "Switzerland", "Syria", "Tajikistan",
            "Tanzania", "Thailand", "Timor-Leste", "Togo", "Tonga", "Trinidad and Tobago",
            "Tunisia", "Turkey", "Turkmenistan", "Tuvalu", "Uganda", "Ukraine", "United Arab Emirates",
            "United Kingdom", "United States of America", "Uruguay", "Uzbekistan", "Vanuatu",
            "Venezuela", "Vietnam", "Yemen", "Zambia", "Zimbabwe"
        ];
        // Get the authenticated user
        $currentUser = $this->getUser();

        // Create and handle the form
        $form = $this->createForm(EditProfileFormType::class, $currentUser,['countries' => $countries]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Save changes
            $entityManager->persist($currentUser);
            $entityManager->flush();

            $this->addFlash('profile_success', 'Profile updated successfully.');

            return $this->redirectToRoute('edit_profile');
        }


        // Second form: Change Password
        $passwordForm = $this->createForm(ChangePasswordFormType::class);
        $passwordForm->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $oldPassword = $passwordForm->get('oldPassword')->getData();
            $newPassword = $passwordForm->get('plainPassword')->getData();

            // Vérifiez si le mot de passe actuel est valide
            if (!$passwordHasher->isPasswordValid($currentUser, $oldPassword)) {
                // Ajoutez une erreur au formulaire
                $passwordForm->addError(new FormError('The current password is incorrect.'));
//                dump($passwordForm->getErrors(true, false));

                // Retournez la vue sans redirection pour afficher l'erreur
                return $this->render('edit_profile/index.html.twig', [
                    'form' => $form->createView(),
                    'passwordForm' => $passwordForm->createView(),
                    'countries' => $countries,
                    'user' => $currentUser,
                ]);
            }

            // Si le mot de passe est valide, hasher le nouveau mot de passe et sauvegarder
            $currentUser->setPassword($passwordHasher->hashPassword($currentUser, $newPassword));
            $entityManager->persist($currentUser);
            $entityManager->flush();

            $this->addFlash('password_success', 'Password changed successfully.');
            return $this->redirectToRoute('edit_profile');
        }

        return $this->render('edit_profile/index.html.twig', [
            'form' => $form->createView(),
            'countries' => $countries, // Adjust your list
            'user' => $currentUser,
            'passwordForm' => $passwordForm->createView(),
        ]);
    }
}
