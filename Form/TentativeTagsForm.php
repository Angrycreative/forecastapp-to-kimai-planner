<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Which Forecast project tags mean the work is not confirmed yet. Forecast itself has no tentative flag.
 */
final class TentativeTagsForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('tags', ChoiceType::class, [
            'label' => 'forecast_import.tentative_tags',
            'help' => 'forecast_import.tentative_tags_help',
            'multiple' => true,
            'expanded' => true,
            'required' => false,
            'choices' => array_combine($options['tags'], $options['tags']),
            'choice_translation_domain' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('tags');
        $resolver->setAllowedTypes('tags', 'array');
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => 'forecast_import_tentative',
        ]);
    }
}
