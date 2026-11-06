<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Form;

use App\Form\Type\DatePickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Account, token and the dates to read. The token is kept, encrypted, only when "Remember" is ticked.
 */
final class ConnectForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('accountId', TextType::class, [
            'label' => 'forecast_import.account_id',
            'help' => 'forecast_import.account_id_help',
            'constraints' => [new Assert\NotBlank(), new Assert\Regex('/^\d+$/', message: 'forecast_import.account_id_invalid')],
        ]);
        $builder->add('token', PasswordType::class, [
            'label' => 'forecast_import.token',
            'help' => $options['token_saved'] ? 'forecast_import.token_help_saved' : 'forecast_import.token_help',
            'always_empty' => true,
            'required' => !$options['token_saved'],
            'attr' => ['autocomplete' => 'off'],
        ]);
        $builder->add('remember', CheckboxType::class, [
            'label' => 'forecast_import.remember_token',
            'help' => 'forecast_import.remember_token_help',
            'required' => false,
        ]);
        $builder->add('begin', DatePickerType::class, [
            'label' => 'forecast_import.begin',
            'help' => 'forecast_import.begin_help',
            'constraints' => [new Assert\NotNull()],
        ]);
        $builder->add('end', DatePickerType::class, [
            'label' => 'forecast_import.end',
            'constraints' => [new Assert\NotNull()],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => 'forecast_import_connect',
            'token_saved' => false,
        ]);
        $resolver->setAllowedTypes('token_saved', 'bool');
    }
}
