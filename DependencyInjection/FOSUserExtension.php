<?php

/*
 * This file is part of the FOSUserBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\UserBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;

class FOSUserExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $processor = new Processor();
        $configuration = new Configuration();

        $config = $processor->processConfiguration($configuration, $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));

        if ('custom' !== $config['db_driver']) {
            $loader->load(sprintf('%s.xml', $config['db_driver']));
            $container->setParameter($this->getAlias() . '.backend_type_' . $config['db_driver'], true);
        }

        foreach (array('validator', 'security', 'util', 'mailer', 'listeners') as $basename) {
            $loader->load(sprintf('%s.xml', $basename));
        }

        if ($config['use_flash_notifications']) {
            $loader->load('flash_notifications.xml');
        }

        if ($config['use_listener']) {
            $this->configureDatabaseListner($container, $config['db_driver']);
        }
        
        if ($config['use_username_form_type']) {
            $loader->load('username_form_type.xml');
        }

        $this->remapParametersNamespaces($config, $container, array(
            ''          => array(
                'db_driver' => 'fos_user.storage',
                'firewall_name' => 'fos_user.firewall_name',
                'model_manager_name' => 'fos_user.model_manager_name',
                'user_class' => 'fos_user.model.user.class',
            ),
        ));

        $this->setContainerAliases($container, $config);
        
        $this->loadProfile($config['profile'], $container, $loader);
        $this->loadRegistration($config['registration'], $container, $loader, $config['from_email']);
        $this->loadChangePassword($config['change_password'], $container, $loader);
        $this->loadResetting($config['resetting'], $container, $loader, $config['from_email']);
        $this->loadGroups($config['group'], $container, $loader, $config['db_driver']);
    }
    
    private function setContainerAliases($container, $config)
    {
        $container->setAlias('fos_user.mailer', $config['service']['mailer']);
        $container->setAlias('fos_user.util.email_canonicalizer', $config['service']['email_canonicalizer']);
        $container->setAlias('fos_user.util.username_canonicalizer', $config['service']['username_canonicalizer']);
        $container->setAlias('fos_user.util.token_generator', $config['service']['token_generator']);
        $container->setAlias('fos_user.user_manager', $config['service']['user_manager']);
    }
    
    private function configureDatabaseListner($container, $driver)
    {
        switch ($driver) {
            case 'orm':
                $container->getDefinition('fos_user.user_listener')->addTag('doctrine.event_subscriber');
                return;

            case 'mongodb':
                $container->getDefinition('fos_user.user_listener')->addTag('doctrine_mongodb.odm.event_subscriber');
                return;

            case 'couchdb':
                $container->getDefinition('fos_user.user_listener')->addTag('doctrine_couchdb.event_subscriber');
                return;
            
            // unused case
            // case 'propel':
        }
    }

    private function loadProfile(array $config, ContainerBuilder $container, XmlFileLoader $loader)
    {
        if (empty($config['profile'])) {
            return;
        }
        
        $loader->load('profile.xml');

        $this->remapParametersNamespaces($config['profile'], $container, array(
            'form' => 'fos_user.profile.form.%s',
        ));
    }

    private function loadRegistration(array $config, ContainerBuilder $container, XmlFileLoader $loader, array $fromEmail)
    {
        if (empty($config['registration'])) {
            return;
        }
        
        $loader->load('registration.xml');

        if ($config['registration']['confirmation']['enabled']) {
            $loader->load('email_confirmation.xml');
        }

        if (isset($config['registration']['confirmation']['from_email'])) {
            // overwrite the global one
            $fromEmail = $config['registration']['confirmation']['from_email'];
            unset($config['registration']['confirmation']['from_email']);
        }
        $container->setParameter('fos_user.registration.confirmation.from_email', array($fromEmail['address'] => $fromEmail['sender_name']));

        $this->remapParametersNamespaces($config['registration'], $container, array(
            'confirmation' => 'fos_user.registration.confirmation.%s',
            'form' => 'fos_user.registration.form.%s',
        ));
    }

    private function loadChangePassword(array $config, ContainerBuilder $container, XmlFileLoader $loader)
    {
        if (empty($config['change_password'])) {
            return;
        }
        $loader->load('change_password.xml');

        $this->remapParametersNamespaces($config['change_password'], $container, array(
            'form' => 'fos_user.change_password.form.%s',
        ));
    }

    private function loadResetting(array $config, ContainerBuilder $container, XmlFileLoader $loader, array $fromEmail)
    {
        if (empty($config['resetting'])) {
            return;
        }
        
        $loader->load('resetting.xml');

        if (isset($config['resetting']['email']['from_email'])) {
            // overwrite the global one
            $fromEmail = $config['resetting']['email']['from_email'];
            unset($config['resetting']['email']['from_email']);
        }
        $container->setParameter('fos_user.resetting.email.from_email', array($fromEmail['address'] => $fromEmail['sender_name']));

        $this->remapParametersNamespaces($config['resetting'], $container, array(
            '' => array (
                'token_ttl' => 'fos_user.resetting.token_ttl',
            ),
            'email' => 'fos_user.resetting.email.%s',
            'form' => 'fos_user.resetting.form.%s',
        ));
    }

    private function loadGroups(array $config, ContainerBuilder $container, XmlFileLoader $loader, $dbDriver)
    {
        if (empty($config['group'])) {
            return;
        }
        $loader->load('group.xml');
        if ('custom' !== $dbDriver) {
            $loader->load(sprintf('%s_group.xml', $dbDriver));
        }

        $container->setAlias('fos_user.group_manager', $config['group']['group_manager']);

        $this->remapParametersNamespaces($config['group'], $container, array(
            '' => array(
                'group_class' => 'fos_user.model.group.class',
            ),
            'form' => 'fos_user.group.form.%s',
        ));
    }

    protected function remapParameters(array $config, ContainerBuilder $container, array $map)
    {
        foreach ($map as $name => $paramName) {
            if (array_key_exists($name, $config)) {
                $container->setParameter($paramName, $config[$name]);
            }
        }
    }

    protected function remapParametersNamespaces(array $config, ContainerBuilder $container, array $namespaces)
    {
        foreach ($namespaces as $ns => $map) {
            $this->remapParamatersNamespace($config, $container, $ns, $map);
        }
    }
    
    private function remapParamatersNamespace(array $config, ContainerBuilder $container, $namespace, $map)
    {
        $namespaceConfig = $config;
        if ($namespace) {
            if ( ! array_key_exists($namespace, $config)) {
                return;
            }
            $namespaceConfig = $config[$namespace];
        }
        
        if (is_array($map)) {
            $this->remapParameters($namespaceConfig, $container, $map);
            return;
        }
        
        foreach ($namespaceConfig as $name => $value) {
            $container->setParameter(sprintf($map, $name), $value);
        }
                
    }
}
