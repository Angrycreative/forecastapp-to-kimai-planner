<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Form;

use App\Form\Type\UserType;
use KimaiPlugin\ForecastImportBundle\Import\Target;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Where a Forecast person's or placeholder's planning goes: a Kimai user, or nowhere.
 */
final class PersonMappingForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('action', ChoiceType::class, [
            'label' => 'forecast_import.action',
            'help' => 'forecast_import.action_help_person',
            'expanded' => true,
            'choices' => [
                'forecast_import.action_user' => Target::USER,
                'forecast_import.action_skip_person' => Target::SKIP,
            ],
        ]);
        $builder->add('user', UserType::class, [
            'label' => 'user',
            'required' => false,
            'include_disabled' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MappingData::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'forecast_import_mapping',
            // Kimai's modal fires this when the dialog was saved; the list refreshes the changed row on it
            'attr' => ['data-form-event' => 'forecast_import.mapping'],
        ]);
    }
}
