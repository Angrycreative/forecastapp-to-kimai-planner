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
use KimaiPlugin\ForecastImportBundle\Import\ReservationCatalogue;
use KimaiPlugin\ForecastImportBundle\Import\Target;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Where a Forecast project's planning goes: a customer and optionally one of its projects, time off, reserved time
 * (when the planner offers any), or nowhere.
 */
final class ProjectMappingForm extends AbstractType
{
    use FormTrait;

    public function __construct(private readonly ReservationCatalogue $reservations)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var MappingData|null $data */
        $data = $builder->getData();

        // the reservation already chosen stays a choice, also when the planner no longer offers it
        $reservations = array_flip($this->reservations->selectable());
        if ($data?->reservation !== null && !\in_array($data->reservation, $reservations, true)) {
            $reservations[$this->reservations->name($data->reservation)] = $data->reservation;
        }

        $actions = [
            'forecast_import.action_work' => Target::WORK,
            'forecast_import.action_time_off' => Target::TIME_OFF,
        ];
        if ($reservations !== []) {
            $actions['forecast_import.action_reservation'] = Target::RESERVATION;
        }
        $actions['forecast_import.action_skip_project'] = Target::SKIP;

        $builder->add('action', ChoiceType::class, [
            'label' => 'forecast_import.action',
            'help' => 'forecast_import.action_help_project',
            'expanded' => true,
            'choices' => $actions,
        ]);
        if ($reservations !== []) {
            $builder->add('reservation', ChoiceType::class, [
                'label' => 'forecast_import.reservation',
                'help' => 'forecast_import.reservation_help',
                'required' => false,
                'placeholder' => '',
                'choices' => $reservations,
                'choice_translation_domain' => false,
            ]);
        }
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
        $builder->add('comment', TextareaType::class, [
            'label' => 'comment',
            'help' => 'forecast_import.comment_help',
            'required' => false,
            'attr' => ['rows' => 2, 'maxlength' => 1000],
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
