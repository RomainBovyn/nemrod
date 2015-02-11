<?php
/**
 * Created by PhpStorm.
 * User: maxime
 * Date: 22/01/2015
 * Time: 14:18
 */

namespace Devyn\Component\RAL\Tests\Manager;


class ManagerTestCase extends \PHPUnit_Framework_TestCase
{
    protected $manager;

    protected $repoFactory;

    public function setUp()
    {
        $this->repoFactory = $this->getMockBuilder('Devyn\Component\RAL\Manager\RepositoryFactory')->setConstructorArgs(array('foo'));
        $this->manager = $this->mockManager();
    }

    /**
     * mocks a resource
     */
    protected function getMockedResource($className, $uri, $graph)
    {
        $mockedResource = $this/*->getMockBuilder('Resource')
            ->setMethods(array('setRm', 'getUri', 'getGraph'))*/->getMock('Devyn\Component\RAL\Resource\Resource', array('setRm', 'getUri', 'getGraph'));
        $mockedResource->method('getUri')->willReturn($uri);
        $mockedResource->method('setRm')->willReturn(null);
        $mockedResource->method('getGraph')->willReturn($graph);
        ;

        return $mockedResource;
    }

    /**
     * sets graph for a mocked resource
     */
    protected function getMockedGraph($class,$uri,$props = array())
    {
        $rdfphpgraph = array ($uri => array_merge($props, array ('rdf:type' => array(array ('type' => 'uri', 'value' => $class)))));
        $mockedGraph = $this->getMock('EasyRdf\Graph');
        $mockedGraph->expects($this->any())->method("toRdfPhp")->willReturn($rdfphpgraph);

        return $mockedGraph;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function mockManager()
    {

        $metadata = array (
            'Foo\Bar\ResourceClass' => array ( 'type' => 'foo:Type', 'uriPattern' => ''),
            'Foo\Bar\ResourceClass' => array ( 'type' => 'foo:Type', 'uriPattern' => ''),
        ) ;

        return $this
            ->getMockBuilder('Devyn\Component\RAL\Manager\Manager')
            ->setConstructorArgs(array($this->repoFactory, 'foo'))
            ->setMethods(array('getEventDispatcher'))
            ->getMock();
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function mockUnitOfWork()
    {
        return $this->getMockBuilder('Devyn\Component\RAL\Manager\UnitOfWork')->setConstructorArgs(array($this->manager, 'http://foo.fr'))->getMock();
    }

    /**
     * @param $metadata
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function mockMetadataFactory($metadata)
    {
        $mdf = $this->getMockBuilder('Devyn\Component\RAL\Manager\ClassMetadataFactory')->setMethods(array('getMetaDataFor'))->getMock();
        foreach ($metadata as $class=>$md) {
            $mdf->expects($this->any())->method('getMetadataFor')->with($class)->willReturn($md);
        }

        return $mdf;
    }
} 