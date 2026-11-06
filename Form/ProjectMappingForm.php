<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Form;

use App\Form\FormTrait;
use App\Form\Type\CustomerType;
use KimaiPlugin\ForecastImportBundle\Import\Target;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Where a Forecast project's planning goes: a customer and optionally one of its projects, time off, or nowhere.
 */
final class ProjectMappingForm extends AbstractType
{
    use FormTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var MappingData|null $data */
        $data = $builder->getData();

        $builder->add('action', ChoiceType::class, [
            'label' => 'forecast_import.action',
            'expanded' => true,
            'choices' => [
                'forecast_import.action_work' => Target::WORK,
                'forecast_import.action_time_off' => Target::TIME_OFF,
                'forecast_import.action_skip_project' => Target::SKIP,
            ],
        ]);
        $builder->add('customer', CustomerType::class, [
            'label' => 'customer',
            'required' => false,
            'placeholder' => '',
            'customers' => $data?->customer,
            'project_enabled' => true,
        ]);
        $this->addProject($builder, false, $data?->project, $data?->customer, [
            'label' => 'project',
            'required' => false,
            'activity_enabled' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MappingData::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'forecast_import_mapping',
        ]);
    }
}
