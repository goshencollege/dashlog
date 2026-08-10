<?php

namespace App\Form;

use App\Entity\StorageBackend;
use App\Enum\StorageBackendType as StorageBackendTypeEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class StorageBackendType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = $options['is_new'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr'  => ['placeholder' => 'e.g. Primary Archive'],
                'help'  => 'A friendly label to identify this backend.',
            ])
            ->add('type', EnumType::class, [
                'class'        => StorageBackendTypeEnum::class,
                'label'        => 'Backend Type',
                'choice_label' => fn (StorageBackendTypeEnum $type) => $type->label(),
            ])
            ->add('isActive', CheckboxType::class, [
                'label'    => 'Active',
                'required' => false,
                'help'     => 'Active backends may be used as a log storage destination. Multiple backends may be active at once.',
            ])

            // Local
            ->add('path', TextType::class, [
                'label'    => 'Directory Path',
                'required' => false,
                'attr'     => ['placeholder' => '/var/www/html/var/storage', 'class' => 'font-monospace'],
                'help'     => 'An absolute path inside the container. For a pre-mounted network share (e.g. NFS), point this at the mount point.',
            ])

            // CIFS / SMB
            ->add('cifsHost', TextType::class, [
                'label'    => 'Host',
                'required' => false,
                'attr'     => ['placeholder' => 'fileserver.goshen.edu'],
            ])
            ->add('cifsShare', TextType::class, [
                'label'    => 'Share Name',
                'required' => false,
                'attr'     => ['placeholder' => 'logs'],
            ])
            ->add('cifsRemotePath', TextType::class, [
                'label'    => 'Path Within Share',
                'required' => false,
                'attr'     => ['placeholder' => '(optional)', 'class' => 'font-monospace'],
            ])
            ->add('cifsUsername', TextType::class, [
                'label'    => 'Username',
                'required' => false,
            ])
            ->add('cifsDomain', TextType::class, [
                'label'    => 'Domain / Workgroup',
                'required' => false,
                'attr'     => ['placeholder' => '(optional)'],
            ])
            ->add('cifsPassword', PasswordType::class, [
                'label'        => 'Password',
                'mapped'       => false,
                'required'     => false,
                'always_empty' => true,
                'attr'         => ['autocomplete' => 'off'],
                'help'         => $isNew
                    ? 'Required for CIFS backends. It will be encrypted at rest.'
                    : 'Leave blank to keep the existing password. Paste a new value to replace it.',
            ])

            // S3
            ->add('s3Bucket', TextType::class, [
                'label'    => 'Bucket',
                'required' => false,
            ])
            ->add('s3Region', TextType::class, [
                'label'    => 'Region',
                'required' => false,
                'attr'     => ['placeholder' => 'us-east-1'],
            ])
            ->add('s3Endpoint', TextType::class, [
                'label'    => 'Endpoint URL',
                'required' => false,
                'attr'     => ['placeholder' => '(optional — for S3-compatible providers, e.g. MinIO)'],
                'help'     => 'Leave blank to use AWS. Set this to point at an S3-compatible provider.',
            ])
            ->add('s3PathPrefix', TextType::class, [
                'label'    => 'Path Prefix',
                'required' => false,
                'attr'     => ['placeholder' => '(optional)', 'class' => 'font-monospace'],
            ])
            ->add('s3UsePathStyleEndpoint', CheckboxType::class, [
                'label'    => 'Use path-style endpoint',
                'required' => false,
                'help'     => 'Required by some S3-compatible providers (e.g. MinIO in some configurations).',
            ])
            ->add('s3AccessKeyId', TextType::class, [
                'label'    => 'Access Key ID',
                'required' => false,
                'attr'     => ['autocomplete' => 'off', 'class' => 'font-monospace'],
            ])
            ->add('s3SecretAccessKey', PasswordType::class, [
                'label'        => 'Secret Access Key',
                'mapped'       => false,
                'required'     => false,
                'always_empty' => true,
                'attr'         => ['autocomplete' => 'off', 'class' => 'font-monospace'],
                'help'         => $isNew
                    ? 'Required for S3 backends. It will be encrypted at rest.'
                    : 'Leave blank to keep the existing secret key. Paste a new value to replace it.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StorageBackend::class,
            'is_new'     => true,
        ]);

        $resolver->setAllowedTypes('is_new', 'bool');
    }
}
