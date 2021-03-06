<?php
/*
 * This file is part of the Nemrod package.
 *
 * (c) Conjecto <contact@conjecto.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Conjecto\Nemrod\ElasticSearch;

use Conjecto\Nemrod\ResourceManager\Registry\TypeMapperRegistry;
use EasyRdf\Graph;
use EasyRdf\Resource;
use EasyRdf\Serialiser\JsonLd;
use Elastica\Document;

class ResourceToDocumentTransformer
{
    /**
     * @var SerializerHelper
     */
    protected $esCache;

    /**
     * @var TypeRegistry
     */
    protected $typeRegistry;

    /**
     * @var TypeMapperRegistry
     */
    protected $typeMapperRegistry;

    public function __construct(SerializerHelper $esCache, TypeRegistry $typeRegistry, TypeMapperRegistry $typeMapperRegistry)
    {
        $this->esCache = $esCache;
        $this->typeRegistry = $typeRegistry;
        $this->typeMapperRegistry = $typeMapperRegistry;
    }

    public function transform($uri, $type)
    {
        $index = $this->typeRegistry->getType($type);
        if (!$index) {
            return;
        }

        $index = $index->getIndex()->getName();
        if ($index && $this->esCache->isTypeIndexed($index, $type)) {
            $jsonLdSerializer = new JsonLd();
            $graph = $this->esCache->getRequest($index, $uri, $type)->getQuery()->execute();
            $jsonLd = $jsonLdSerializer->serialise($graph, 'jsonld', ['context' => $this->esCache->getTypeContext($index, $type), 'frame' => $this->esCache->getTypeFrame($index, $type)]);
            $graph = json_decode($jsonLd, true);
            if (!isset($graph['@graph'][0])) {
                return;
            }
            $json = json_encode($graph['@graph'][0]);
            $json = str_replace('@id', '_id', $json);
            $json = str_replace('@type', '_type', $json);

            return new Document($uri, $json, $type, $index);
        }

        return;
    }

    public function reverseTransform(Document $document)
    {
        if ($document) {
            $uri = $document->getParam('_id');
            $data = $document->getData();
            $data = json_decode($data, true);

            if (!isset($data['@graph'][0])) {
                return;
            }

            $data = json_encode($data['@graph'][0]);
            $data = str_replace('_type', 'rdf:type', $data);
            $data = json_decode($data, true);
            unset($data['_id']);

            $graph = new Graph($uri);
            foreach ($data as $property => $value) {
                $graph->add($uri, $property, $value);
            }
            $phpClass = $this->typeMapperRegistry->get($data['rdf:type']);
            if ($phpClass) {
                $res = new $phpClass($uri, $graph);

                return $res;
            }

            return new \Conjecto\Nemrod\Resource($uri, $graph);
        }

        return;
    }
}
