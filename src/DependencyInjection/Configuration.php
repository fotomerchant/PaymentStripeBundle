<?php

namespace Ruudk\Payment\StripeBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('ruudk_payment_stripe');

        $methods = array('checkout');

        $rootNode
            ->children()
                ->scalarNode('api_key')
                    ->defaultNull()
                ->end()
                ->scalarNode('processes_type')
                    ->defaultValue('stripe_checkout')
                ->end()
                ->booleanNode('logger')
                    ->defaultTrue()
                ->end()
            ->end()

            ->fixXmlConfig('instance')
            ->children()
                ->arrayNode('instances')
                    ->defaultValue([])
                    ->prototype('array')
                        ->children()
                            ->scalarNode('api_key')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('processes_type')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()

            ->fixXmlConfig('method')
            ->children()
                ->arrayNode('methods')
                    ->defaultValue($methods)
                    ->prototype('scalar')
                        ->validate()
                            ->ifNotInArray($methods)
                            ->thenInvalid('%s is not a valid method.')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
