<?php


namespace App\Form;


use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

class EditProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'Username',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('phoneNumber', TextType::class, [
                'label' => 'Phone Number',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('dateOfBirth', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Date of Birth',
                'attr' => ['class' => 'form-control'],
                'required' => false,
            ])
            ->add('gender', ChoiceType::class, [
                'choices' => [
                    'Male' => 'Male',
                    'Female' => 'Female',
                ],
                'expanded' => true, // Utilise des boutons radio
                'multiple' => false, // Une seule sélection possible
                'label' => 'Gender',
                'attr' => [
                    'class' => 'form-check-input',// Classe pour le style des boutons radio
                ],
                'label_attr' => [
                    'class' => 'form-check-label radio-label', // Classe pour espacer les labels
                    'style' => 'color : #212529'
                ],
            ])

            ->add('country', ChoiceType::class, [
                'choices' => array_combine($options['countries'], $options['countries']), // Dynamically map countries
                'label' => 'Country',
                'placeholder' => 'Select a Country', // Add a placeholder for better UX
                'attr' => ['class' => 'form-control'],
            ])

            ->add('phoneNumber', TextType::class, [
                'label' => 'Phone Number',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Address',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class, // Bind form to the User entity
            'countries' => [],          // Allow dynamic country list
        ]);
    }
}
