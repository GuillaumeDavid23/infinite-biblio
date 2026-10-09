<?php

namespace App\Form;

use App\Model\NovelBrief;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class NovelBriefType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('premise', TextareaType::class, ['label' => 'Idée de départ', 'help' => 'Une ou deux phrases suffisent.', 'attr' => ['rows' => 3]])
            ->add('genre', TextType::class, ['label' => 'Genre'])
            ->add('subgenre', TextType::class, ['label' => 'Sous-genre', 'required' => false])
            ->add('chapterCount', IntegerType::class, ['label' => 'Nombre de chapitres', 'help' => 'Commencez par 3 pour un premier essai.', 'attr' => ['min' => 1, 'max' => $options['max_chapters']]])
            ->add('targetWordsPerChapter', IntegerType::class, ['label' => 'Longueur visée par chapitre (mots)', 'help' => 'C\'est une cible : le texte n\'est jamais allongé artificiellement.', 'attr' => ['min' => 300, 'max' => 5000, 'step' => 100]])
            ->add('budgetDollars', TextType::class, ['label' => 'Budget maximal pour ce roman (dollars US)', 'help' => 'La génération se met en pause avant de dépasser ce montant.'])
            ->add('title', TextType::class, ['label' => 'Titre (facultatif)', 'required' => false, 'help' => 'Laissé vide, l\'IA en proposera un.'])
            ->add('setting', TextareaType::class, ['label' => 'Univers et lieux', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('era', TextType::class, ['label' => 'Époque', 'required' => false])
            ->add('characters', TextareaType::class, ['label' => 'Personnages principaux', 'required' => false, 'help' => 'Un personnage par ligne.', 'attr' => ['rows' => 3]])
            ->add('pointOfView', ChoiceType::class, ['label' => 'Point de vue', 'choices' => NovelBrief::POINTS_OF_VIEW])
            ->add('tense', ChoiceType::class, ['label' => 'Temps du récit', 'choices' => NovelBrief::TENSES])
            ->add('mood', TextType::class, ['label' => 'Ambiance', 'required' => false])
            ->add('pace', ChoiceType::class, ['label' => 'Rythme', 'choices' => NovelBrief::PACES])
            ->add('audience', ChoiceType::class, ['label' => 'Public visé', 'choices' => NovelBrief::AUDIENCES])
            ->add('themes', TextareaType::class, ['label' => 'Thèmes souhaités', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('avoid', TextareaType::class, ['label' => 'Éléments à éviter', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('autoContinue', CheckboxType::class, ['label' => 'Enchaîner automatiquement les étapes (contrôle, mémoire, chapitre suivant)', 'required' => false])
            ->add('autoAccept', CheckboxType::class, ['label' => 'Accepter automatiquement les chapitres sans alerte bloquante', 'required' => false, 'help' => 'Décoché : vous validez chaque chapitre avant la suite.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NovelBrief::class, 'max_chapters' => 60]);
    }
}
