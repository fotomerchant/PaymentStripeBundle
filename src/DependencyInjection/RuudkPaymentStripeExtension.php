<?php

namespace Ruudk\Payment\StripeBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class RuudkPaymentStripeExtension extends Extension
{
    /**
     * @param array            $configs
     * @param ContainerBuilder $container
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        // If an API Key is provided, we will setup the 'default' stripe checkout plugin for 'stripe_checkout'
        // payment types. This ensure no BC breaks for this bundle.
        if (isset($config['api_key'])) {
            $this->addCheckoutPluginInstance($container, 'default', $config);

            // Add aliases for BC
            $container->setAlias('ruudk_payment_stripe.gateway', 'ruudk_payment_stripe.gateway.default');
            $container->setAlias('ruudk_payment_stripe.plugin.checkout', 'ruudk_payment_stripe.plugin.checkout.default');
        }

        foreach($config['instances'] AS $instance => $options) {
            $this->addCheckoutPluginInstance($container, $instance, $options);
        }

        foreach($config['methods'] AS $method) {
            $this->addFormType($container, $method);
        }

        /**
         * When logging is disabled, remove logger and setLogger calls
         */
        if(false === $config['logger']) {
            $container->getDefinition('ruudk_payment_stripe.plugin.credit_card')->removeMethodCall('setLogger');
            $container->removeDefinition('monolog.logger.ruudk_payment_stripe');
        }
    }

    protected function addFormType(ContainerBuilder $container, $method)
    {
        $stripeMethod = 'stripe_' . $method;

        $definition = new Definition();
        if($container->hasParameter(sprintf('ruudk_payment_stripe.form.%s_type.class', $method))) {
            $definition->setClass(sprintf('%%ruudk_payment_stripe.form.%s_type.class%%', $method));
        } else {
            $definition->setClass('%ruudk_payment_stripe.form.stripe_type.class%');
        }
        $definition->addArgument($stripeMethod);

        $definition->addTag('payment.method_form_type');
        $definition->addTag('form.type', array(
            'alias' => $stripeMethod
        ));

        $container->setDefinition(
            sprintf('ruudk_payment_stripe.form.%s_type', $method),
            $definition
        );
    }

    private function addCheckoutPluginInstance($container, $instance, $options)
    {
        $gatewayClass = '%ruudk_payment_stripe.gateway.class%';
        $checkoutClass = '%ruudk_payment_stripe.plugin.checkout.class%';

        if ($options['type'] === 'payment_intents') {
            $gatewayClass = '%ruudk_payment_stripe.payment_intents_gateway.class%';
            $checkoutClass = '%ruudk_payment_stripe.plugin.checkout_payment_intents.class%';
        }

        $gatewayDefinition = new Definition($gatewayClass, [null, new Reference('request', ContainerInterface::NULL_ON_INVALID_REFERENCE, false)]);
        $gatewayDefinition->addMethodCall('setApiKey', [ $options['api_key'] ]);
        $container->setDefinition('ruudk_payment_stripe.gateway.'.$instance, $gatewayDefinition);

        $pluginDefinition = new Definition($checkoutClass, [ new Reference('ruudk_payment_stripe.gateway.'.$instance) ]);
        $pluginDefinition->addMethodCall('setLogger', [ new Reference('monolog.logger.ruudk_payment_stripe') ]);
        $pluginDefinition->addMethodCall('setProcessesType', [ $options['processes_type'] ]);
        $pluginDefinition->addTag('payment.plugin');
        $container->setDefinition('ruudk_payment_stripe.plugin.checkout.'.$instance, $pluginDefinition);
    }
}
