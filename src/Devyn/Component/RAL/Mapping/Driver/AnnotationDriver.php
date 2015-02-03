<?php
namespace Devyn\Component\RAL\Mapping\Driver;

use Devyn\Component\RAL\Mapping\ClassMetadata;
use Devyn\Component\RAL\Mapping\PropertyMetadata;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Persistence\Mapping\Driver\AnnotationDriver as AbstractAnnotationDriver;
use Metadata\Driver\AdvancedDriverInterface;
use Devyn\Component\RAL\Annotation\Rdf\Property as RdfProperty;

/**
 * Class AnnotationDriver parses a bundle for
 * @package Devyn\Component\RAL\Mapping\Driver
 */
class AnnotationDriver implements AdvancedDriverInterface
{
    /**
     * @var
     */
    private $dirs;


    private $reader;


    /**
     * @param Reader $reader
     */
    public function __construct(Reader $reader, $dirs)
    {
        $this->reader = $reader;
        $this->dirs = $dirs;
    }


    /**
     * @param \ReflectionClass $class
     * @return \Metadata\ClassMetadata
     */
    public function loadMetadataForClass(\ReflectionClass $class)
    {
        $metadata = new ClassMetadata($class->getName());
        $classAnnotations = $this->reader->getClassAnnotations($class);

        if ($classAnnotations) {
            foreach ($classAnnotations as $key => $annot) {
                if ( ! is_numeric($key)) {
                    continue;
                }
                $classAnnotations[get_class($annot)] = $annot;
            }
        }

        foreach ($class->getProperties() as $prop) {
            $propertyAnnotations = $this->reader->getPropertyAnnotations($prop);
            foreach($propertyAnnotations as $propAnnot) {
                if ($propAnnot instanceof RdfProperty) {
                    $propMetadata = new PropertyMetadata($class->getName(), $prop->getName());
                    $propMetadata->value = $propAnnot->value;
                    $propMetadata->cascade = $propAnnot->cascade;
                    $metadata->addPropertyMetadata($propMetadata);
                }
            }
            if (isset($propertyAnnotations['Devyn\Component\RAL\Annotation\Rdf\Property'])) {
               // var_dump($propertyAnnotations['Devyn\Component\RAL\Annotation\Rdf\Property']);
            }
        }


        // Evaluate Resource annotation
        if (isset($classAnnotations['Devyn\Component\RAL\Annotation\Rdf\Resource'])) {

            $resourceAnnot = $classAnnotations['Devyn\Component\RAL\Annotation\Rdf\Resource'];

            $types = $resourceAnnot->types;
            $pattern = $resourceAnnot->uriPattern;
            if(!is_array($types)) {
                $types = array($types);
            }
            $metadata->types = $types;
            $metadata->uriPattern = $pattern;
        }


        return $metadata;
    }

    /**
     * @return array
     */
    public function getAllClassNames()
    {
        $classes = array();
        foreach ($this->dirs as $nsPrefix => $dir) {
            /** @var $iterator \RecursiveIteratorIterator|\SplFileInfo[] */
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {

                if (($fileName = $file->getBasename('.php')) == $file->getBasename()) {
                    continue;
                }
                $classes[] = $nsPrefix.'\\RdfResource\\'.str_replace('.', '\\', $fileName);
            }
        }

        return $classes;
    }
}
