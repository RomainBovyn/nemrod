<?php

namespace Devyn\Bundle\RdfFrameworkBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * FrameworkExtension configuration structure.
 */
class Configuration implements ConfigurationInterface
{
    /**
     * Generates the configuration tree builder.
     *
     * @return TreeBuilder The tree builder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('framework');

        $this->addNamespaceSection($rootNode);

        return $treeBuilder;
    }

    /**
     * @param ArrayNodeDefinition $rootNode
     */
    private function addNamespaceSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->children()
                ->arrayNode('namespaces')
                    ->prototype('array')
                        ->beforeNormalization()
                        ->ifString()
                            ->then(function($v) { return array('uri'=> $v); })
                        ->end()
                        ->children()
                            ->scalarNode('uri')->isRequired()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
