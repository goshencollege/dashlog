<?php

namespace App\Form;

use App\Entity\SamlProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class SamlProviderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = $options['is_new'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'Provider Name',
                'attr'  => ['placeholder' => 'e.g. Okta Production'],
                'help'  => 'A friendly label to identify this provider.',
            ])
            ->add('spEntityId', UrlType::class, [
                'label' => 'SP Entity ID',
                'attr'  => ['placeholder' => 'https://dashlog.goshen.edu/saml/metadata'],
            ])
            ->add('spAcsUrl', UrlType::class, [
                'label' => 'SP Assertion Consumer Service URL',
                'attr'  => ['placeholder' => 'https://dashlog.goshen.edu/saml/acs'],
            ])
            ->add('spSloUrl', UrlType::class, [
                'label' => 'SP Single Logout URL',
                'attr'  => ['placeholder' => 'https://dashlog.goshen.edu/saml/logout'],
            ])
            ->add('spCert', TextareaType::class, [
                'label' => 'SP Certificate (public)',
                'attr'  => ['rows' => 4, 'class' => 'font-monospace', 'placeholder' => 'Base64-encoded certificate — no headers'],
                'help'  => 'Paste the base64 body only (no -----BEGIN CERTIFICATE----- headers).',
            ])
            ->add('spPrivateKey', PasswordType::class, [
                'label'       => 'SP Private Key',
                'mapped'      => false,
                'required'    => $isNew,
                'always_empty' => true,
                'attr'        => ['autocomplete' => 'off', 'class' => 'font-monospace'],
                'help'        => $isNew
                    ? 'Paste the base64 private key body (no headers). It will be encrypted at rest.'
                    : 'Leave blank to keep the existing key. Paste a new value to replace it.',
                'constraints' => $isNew ? [new NotBlank(message: 'The SP private key is required.')] : [],
            ])
            ->add('idpEntityId', TextType::class, [
                'label' => 'IdP Entity ID',
                'attr'  => ['placeholder' => 'http://www.okta.com/exkXXXXXXXXXXXXXX'],
            ])
            ->add('idpSsoUrl', UrlType::class, [
                'label' => 'IdP SSO URL',
                'attr'  => ['placeholder' => 'https://login.example.com/app/...'],
            ])
            ->add('idpCert', TextareaType::class, [
                'label' => 'IdP Certificate',
                'attr'  => ['rows' => 4, 'class' => 'font-monospace', 'placeholder' => 'Base64-encoded certificate — no headers'],
                'help'  => 'Paste the base64 body from the IdP signing certificate (no -----BEGIN CERTIFICATE----- headers).',
            ])
            ->add('sessionLifetimeMinutes', IntegerType::class, [
                'label' => 'Session Timeout (minutes)',
                'attr'  => ['min' => 1, 'max' => 1440],
                'help'  => 'How long users can be inactive before they must log in again. Default is 30 minutes.',
            ])
            ->add('roleAttribute', TextType::class, [
                'label'    => 'Role Attribute',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. groups'],
                'help'     => 'Name of the SAML attribute whose asserted value(s) are role identifiers (e.g. ROLE_ADMIN). '
                    . 'Configure your IdP to send this attribute with the right role for each user. '
                    . 'Leave blank to disable SSO-driven roles — everyone gets User access.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SamlProvider::class,
            'is_new'     => true,
        ]);

        $resolver->setAllowedTypes('is_new', 'bool');
    }
}
