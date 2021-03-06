<?php
/*
 * This file is part of the Nemrod package.
 *
 * (c) Conjecto <contact@conjecto.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Conjecto\Nemrod\ResourceManager;

use Conjecto\Nemrod\QueryBuilder\Query;
use Conjecto\Nemrod\QueryBuilder;
use EasyRdf\Collection;
use EasyRdf\Exception;
use EasyRdf\Graph;
use EasyRdf\Sparql\Result;
use EasyRdf\TypeMapper;

/**
 * Class Persister.
 */
class SimplePersister implements PersisterInterface
{
    /** @var Manager */
    private $_rm;

    /** @var  a count for setting uri on collection */
    private $collectionUriCount = 0;

    /** @var  int $variableCount */
    private $variableCount = 0;

    private $bnodeMap = array();

    /**  */
    public function __construct($rm)
    {
        $this->_rm = $rm;
    }

    private function updateQuery($string)
    {
        $this->_rm->getClient()->update($string);
    }

    /**
     * @todo should be renamed
     *
     * @param $className
     * @param $uri
     *
     * @return Resource
     *
     * @throws Exception
     */
    public function constructUri($className, $uri)
    {
        //echo "plop";
        $body = "<".$uri.">".(($className != null) ? " a ".($className).";" : "")." ?p ?q";
        /** @var QueryBuilder $qb */
        $qb = $this->_rm->getQueryBuilder();
        $qb->construct($body)->where($body);

        $result = $qb->getQuery()->execute();

        if ($result instanceof Result) {
            $result = $this->resultToGraph($result);
        }

        if (!$this->isEmpty($result)) {
            $resourceClass = null;
            foreach ($result->all($uri, 'rdf:type') as $type) {
                $resourceClass = TypeMapper::get($type->getUri());
                if ($resourceClass != null) {
                    break;
                }
            }

            if (empty($resourceClass)) {
                $resourceClass = 'Conjecto\Nemrod\Resource';
            }

            $resource = $this->resultToResource($uri, $result, $resourceClass);

            if ($resource) {
                $this->registerResource($resource);
            }

            return $resource;
        }

        return;
    }

    /**
     * @param $owningUri
     * @param $property
     *
     * @return mixed
     *
     * @throws Exception
     */
    public function constructBNode($owningUri, $property)
    {
        $body = "<".$owningUri."> ".$property." ?bnodeVar. ?bnodeVar ?p ?q.";

        $qb = $this->_rm->getQueryBuilder();
        $qb->construct($body)->where($body);

        $graph = $qb->getQuery()->execute();

        if (!$graph instanceof Graph) {
            $graph = $this->resultToGraph($graph);
        }

        $this->_rm->getUnitOfWork()->setBNodes($owningUri, $property, $graph);

        return $graph->get($owningUri, $property);
    }

    /**
     * @param $uri
     * @param $delete
     * @param $insert
     * @param $where
     *
     * @return Graph|Result
     */
    public function update($uri, $delete, $insert, $where)
    {
        list($deleteTriples, $whereTriples) = $this->phpRdfToSparqlBody($delete, true);
        list($insertTriples) = $this->phpRdfToSparqlBody($insert);

        //echo htmlspecialchars("DELETE {".$deleteStr."} INSERT {".$insertStr."} WHERE {".$whereStr."}");die();
        $qb = $this->_rm->getQueryBuilder();

        foreach ($deleteTriples as $del) {
            $qb->addDelete($del);
        }

        foreach ($insertTriples as $ins) {
            $qb->addInsert($ins);
        }

        $unions = array();
        foreach ($whereTriples as $where) {
            if (is_array($where)) {
                $unions = array_merge($unions, $where);
            } else {
                $qb->andWhere($where);
            }
        }
        if (count($unions) == 1) {
            $unions[] = "";
        }
        if (count($unions)) {
            $qb->addUnion($unions);
        }

        $q = $qb->getQuery();
        //echo htmlspecialchars( $q->getSparqlQuery());
        $result = $q->update();

        return $result;
    }

    /**
     * @todo second param is temporary
     *
     * @param array $criteria
     * @param array $options
     *
     * @throws Exception
     *
     * @internal param bool $asArray
     *
     * @return Collection|void
     */
    public function constructSet(array $criteria, array $options, $hydrate = Query::HYDRATE_ARRAY)
    {
        //end statments for query (order by, etc)
        $queryFinal = "";
        $criteriaParts = array();
        $criteriaUnionParts = array();

        if (!empty($criteria)) {
            foreach ($criteria as $property => $value) {
                if (is_array($value)) {
                    if (!empty($value)) {
                        foreach ($value as $val) {
                            $criteriaParts[] = $property." ".$val;
                        }
                    }
                }
                if ($value == "") {
                    $criteriaParts[] = $property." \"\"";
                } else {
                    $criteriaParts[] = $property." ".$value;
                }
            }
        }

        if (isset($options['orderBy'])) {
            $criteriaUnionParts[] = "?s ".$options['orderBy']." ?orderingvar";
            $queryFinal .= "?orderingvar";
        }

        $qb = $this->_rm->getQueryBuilder();
        $qb->construct("?s ?p ?q");
        $qb->where("?s ?p ?q");

        foreach ($criteriaParts as $triple) {
            $qb->addConstruct("?s ".$triple);
            $qb->andWhere("?s ".$triple);
        }

        foreach ($criteriaUnionParts as $triple) {
            $qb->addConstruct($triple);
        }

        if (count($criteriaUnionParts) == 1) {
            $criteriaUnionParts[] = "";
        }

        if (count($criteriaUnionParts)) {
            $qb->addUnion($criteriaUnionParts);
        }

        $qb->setOffset(0);
        if ($queryFinal != "") {
            $qb->orderBy($queryFinal);
        }

        if (isset($options['limit']) && is_numeric($options['limit'])) {
            $qb->setMaxResults($options['limit']);
        }

        $result = $qb->getQuery()->execute($hydrate, array('rdf:type' => $criteria['rdf:type']));

        if ($result instanceof Result) {
            $result = $this->resultToGraph($result);
        }

        if ($this->isEmpty($result)) {
            return;
        }

        $output = null;

        //extraction of collection is done by unit of work
        if (!empty($criteria['rdf:type'])) {
            if ($hydrate == Query::HYDRATE_COLLECTION) {
                if (is_array($criteria['rdf:type'])) {
                    $rdfTtype = $criteria['rdf:type'][0];
                } else {
                    $rdfTtype = $criteria['rdf:type'];
                }
                $output = $this->extractResources($result, $rdfTtype);
            } elseif ($hydrate == Query::HYDRATE_ARRAY) {
                foreach ($result as $re) {
                    $this->declareResource($re);
                }
                $output = $result;
            }
        }

        return $output;
    }

    /**
     * Declares a resource to unit of work. Either the resource is already managed, and the UOW performs an update, or
     * the resource is not managed, and is registered.
     *
     * @param $resource
     */
    private function declareResource($resource)
    {
        if ($this->_rm->getUnitOfWork()->isManaged($resource)) {
            $this->_rm->getUnitOfWork()->replaceResourceInstance($resource);
        } else {
            $this->_rm->getUnitOfWork()->registerResource($resource);
        }
    }

    /**
     * @param $criteria
     * @param bool $bNodesAsVariables
     *
     * @return array
     */
    private function phpRdfToSparqlBody($criteria, $bNodesAsVariables = false)
    {
        $criteriaParts = array();
        $whereParts = array();
        if (!empty($criteria)) {
            foreach ($criteria as $uri => $properties) {
                //depending on the way we want to generate triples for bnodes

                if (!$this->_rm->getUnitOfWork()->isBNode($uri) // not a bnode
                    || $bNodesAsVariables) {
                    //or a bnode, but we have to treat it as a variable

                    list($a, $b) = $this->getTriplesForUri($criteria, $uri, $bNodesAsVariables, true);
                    $criteriaParts = array_merge($criteriaParts, $a);
                    $whereParts = array_merge($whereParts, $b);
                }
            }
        }

        return array( $criteriaParts,$whereParts);
    }

    /**
     * get an array of triples as strings for a given URI.
     *
     * @param $array
     * @param $uri
     * @param $bNodesAsVariables
     * @param bool $followBNodes
     *
     * @return array
     */
    private function getTriplesForUri($array, $uri, $bNodesAsVariables, $followBNodes = false)
    {
        $criteriaParts = array();
        $whereParts = array();

        if ($this->_rm->getUnitOfWork()->isBNode($uri)) {
            $oldUri = $uri;
            $uri = $this->getNewBnode($uri);
            if (isset($array[$oldUri])) {
                $array[$uri] = $array[$oldUri];
                unset($array[$oldUri]);
            }
        }

        if (isset($array[$uri])) {
            foreach ($array[$uri] as $property => $value) {
                //all triples are removed. UpLink are also removed.
                if ($property == 'all') {
                    $varObj = $this->nextVariable();
                    $varPred = $this->nextVariable();
                    $varUpSubj = $this->nextVariable();
                    $varUpPred = $this->nextVariable();
                    $criteriaParts[] = "<".$uri."> ".$varObj." ".$varPred;
                    $whereParts[] = array("<".$uri."> ".$varObj." ".$varPred);
                    $criteriaParts[] = $varUpSubj." ".$varUpPred." <".$uri.">";
                    $whereParts[] = array($varUpSubj." ".$varUpPred." <".$uri.">");
                } elseif (is_array($value)) {
                    if (!empty($value)) {
                        foreach ($value as $val) {
                            if ($val['type'] == 'literal') {
                                $criteriaParts[] = "<".$uri."> <".$property."> \"".$val['value']."\"";
                                $whereParts[] = "<".$uri."> <".$property."> \"".$val['value']."\"";
                            } elseif ($val['type'] == 'uri') {
                                $criteriaParts[] = "<".$uri."> <".$property."> <".$val['value'].">";
                                $whereParts[] = "<".$uri."> <".$property."> <".$val['value'].">";
                            } elseif ($val['type'] == 'bnode') {
                                if ($bNodesAsVariables) {
                                    $varBnode = $this->nextVariable();
                                    $varBnodePred = $this->nextVariable();
                                    $varBnodeObj = $this->nextVariable();
                                    $criteriaParts[] = "<".$uri."> <".$property."> ".$varBnode;
                                    $criteriaParts[] = $varBnode." ".$varBnodePred." ".$varBnodeObj;
                                    $whereParts[] = "<".$uri."> <".$property."> ".$varBnode;
                                } else {
                                    $newBNode = $this->getNewBnode($val['value']);

                                    $criteriaParts[] = "<".$uri."> <".$property."> ".$newBNode."";
                                    $whereParts[] = "<".$uri."> <".$property."> ".$newBNode."";
                                    if ($followBNodes) {
                                        list($a, $b) = $this->getTriplesForUri($array, $val['value'], $bNodesAsVariables, false);
                                        $criteriaParts = array_merge($criteriaParts, $a);
                                        $whereParts = array_merge($whereParts, $b);
                                    }
                                }
                            } elseif ($val['type'] == 'uri') {
                                $criteriaParts[] = "<".$uri."> <".$property."> ".$val['value']."";
                            }
                        }
                    }
                }
            }
        }

        return array($criteriaParts, $whereParts);
    }

    /**
     * @param $graph
     * @param $className
     *
     * @return Collection
     */
    private function extractResources($graph, $className)
    {
        $res = $graph->allOfType($className);

        $collUri = $this->nextCollectionUri();
        $this->_rm->getUnitOfWork()->managementBlackList($collUri);
        $coll = new Collection($collUri, $graph);

        //@todo WAY too long
        //building collection
        foreach ($res as $re) {
            $coll->append($re);
        }

        $this->_rm->getUnitOfWork()->blackListCollection($coll);

        foreach ($res as $re) {
            $this->declareResource($re);
        }

        return $coll;
    }

    /**
     * @param Collection $coll
     */
    public function blackListCollection(Collection $coll)
    {
        //going to first element.
        $coll->rewind();
        $ptr = $coll;
        $head = $ptr->get('rdf:first');
        $next = $ptr->get('rdf:rest');

        //putting all structure collection on a blacklist
        while ($head) {
            $this->_rm->getUnitOfWork()->managementBlackList($next->getUri());
            $head = $next->get('rdf:first');
            $next = $next->get('rdf:rest');
        }

        //and resetting pointer of collection
        $coll->rewind();
    }

    /**
     * Builds and return Resource of the corresponding type with provided uri and result.
     *
     * @param null   $uri
     * @param Graph  $graph
     * @param string $resourceClass
     *
     * @return Resource
     */
    private function resultToResource($uri = null, Graph $graph, $resourceClass)
    {
        $resource = new $resourceClass($uri, $graph);

        return $resource;
    }

    /**
     * //@todo not used anymore
     * temp function : converting a result to a graph.
     *
     * @param Result $result
     *
     * @return Graph
     */
    private function resultToGraph($result)
    {
        //@todo
        if ($result instanceof Graph) {
            return $result;
        }

        $graph = new Graph(null);

        foreach ($result as $row) {
            $graph->add($row->subject, $row->predicate, $row->object);
        }

        return $graph;
    }

    /**
     * calls the resource registration process of resource manager's unit of work.
     *
     * @param $resource
     */
    private function registerResource($resource)
    {
        //echo 'reg'.$resource->getUri();
        $this->_rm->getUnitOfWork()->registerResource($resource);
    }

    /**
     * provides a blank node uri for collections.
     *
     * @return string
     */
    private function nextCollectionUri()
    {
        return "_:internalcollection".(++$this->collectionUriCount);
    }

    /**
     * provides a blank node uri for collections.
     *
     * @return string
     */
    private function nextVariable()
    {
        return "?var".(++$this->variableCount);
    }

    /**
     * Get existing new bnode or create new bnode for given bnode.
     *
     * @param $bNode
     */
    public function getNewBnode($bNode)
    {
        if (in_array($bNode, $this->bnodeMap)) {
            return $bNode;
        }
        if (!isset($this->bnodeMap[$bNode])) {
            $this->bnodeMap[$bNode] = $this->_rm->getUnitOfWork()->nextBNode();
        }

        return $this->bnodeMap[$bNode];
    }

    private function isEmpty($result)
    {
        if ($result instanceof Graph) {
            return $result->isEmpty();
        } elseif ($result instanceof Result) {
            $cnt = count($result);

            return ($cnt == 0);
        }
    }
}
