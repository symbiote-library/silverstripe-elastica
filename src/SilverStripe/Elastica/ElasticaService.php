<?php

namespace Symbiote\Elastica;

use Elastica\Exception\Connection\HttpException;
use Elastica\Client;
use Elastica\Query;
use Elastica\Query\MultiMatch;

use Elastica\Type\Mapping;

/**
 * A service used to interact with elastic search.
 */
class ElasticaService {
    
    /**
     * Custom mapping definitions
     * 
     * Format of array(
     *   'type' => array(
     *     'FieldA' => array('type' => elastictype, 'etc' => other)
     *     'FieldB' => array('type' => elastictype, 'etc' => other)
     *   )
     * )
     *
     * @var array
     */
    public $mappings = array();

	/**
	 * @var \Elastica\Document[]
	 */
	protected $buffer = array();

	/**
	 * @var bool controls whether indexing operations are buffered or not
	 */
	protected $buffered = false;

    /**
     * @var Elastica\Client
     */
	private $client;
    
    /**
     * @var string
     */
	private $index;
    
    public $enabled = true;
    
    protected $connected = true;
    
	/**
	 * @param \Elastica\Client $client
	 * @param string $index
	 */
	public function __construct(Client $client, $index) {
		$this->client = $client;
		$this->index = $index;
	}

	/**
	 * @return \Elastica\Client
	 */
	public function getClient() {
		return $this->client;
	}

	/**
	 * @return \Elastica\Index
	 */
	public function getIndex() {
		return $this->getClient()->getIndex($this->index);
	}

	/**
	 * Performs a search query and returns a result list.
	 *
	 * @param \Elastica\Query|string|array $query
	 * @param array $fields An optional list of fields to search
	 * @return ResultList
	 */
	public function search($query, $fields = array ()) {
		if(!empty($fields)) {
			$q = new MultiMatch();
			$q->setQuery($query);
			$q->setFields($fields);
		}
		else {
			$q = Query::create($query);
		}

		return new ResultList($this->getIndex(), $q);
	}
    
	/**
	 * Either creates or updates a record in the index.
	 *
	 * @param Searchable $record
	 */
	public function index($record, $stage = 'Stage') {
        if (!$this->enabled) {
            return;
        }
		$document = $record->getElasticaDocument($stage);
		$type = $record->getElasticaType();

        $this->indexDocument($document, $type);
	}
    
    public function indexDocument($document, $type) {
        if (!$this->enabled) {
            return;
        }
        if (!$this->connected) {
            return;
        }
        if ($this->buffered) {
			if (array_key_exists($type, $this->buffer)) {
				$this->buffer[$type][] = $document;
			} else {
				$this->buffer[$type] = array($document);
			}
		} else {
			$index = $this->getIndex();
            try {
                $index->getType($type)->addDocument($document);
                $index->refresh();  
            } catch (HttpException $ex) {
                $this->connected = false;
                
                // TODO LOG THIS ERROR
                \SS_Log::log($ex, \SS_Log::ERR);
            }
		}
    }

	/**
	 * Begins a bulk indexing operation where documents are buffered rather than
	 * indexed immediately.
	 */
	public function startBulkIndex() {
		$this->buffered = true;
	}

	/**
	 * Ends the current bulk index operation and indexes the buffered documents.
	 */
	public function endBulkIndex() {
        if (!$this->connected) {
            return;
        }

		$index = $this->getIndex();

        try {
            foreach ($this->buffer as $type => $documents) {
                $index->getType($type)->addDocuments($documents);
                $index->refresh();
            }
        } catch (HttpException $ex) {
            $this->connected = false;

            // TODO LOG THIS ERROR
            \SS_Log::log($ex, \SS_Log::ERR);
        } catch (\Elastica\Exception\BulkException $be) {
            \SS_Log::log($be, \SS_Log::ERR);
            throw $be;
        }

		$this->buffered = false;
		$this->buffer = array();
	}

	/**
	 * Deletes a record from the index.
	 *
	 * @param Searchable $record
	 */
	public function remove($record, $stage = 'Stage') {
		$index = $this->getIndex();
		$type = $index->getType($record->getElasticaType());

        try {
            $type->deleteDocument($record->getElasticaDocument($stage));
        } catch (\Exception $ex) {
            \SS_Log::log($ex, \SS_Log::WARN);
            return false;
        }
        
        return true;
	}

	/**
	 * Creates the index and the type mappings.
	 */
	public function define() {
		$index = $this->getIndex();

		if (!$index->exists()) {
			$index->create();
		}

		$this->createMappings($index);
	}
    
    /**
     * Define all known mappings
     */
    protected function createMappings(\Elastica\Index $index) {
        foreach ($this->getIndexedClasses() as $class) {
			/** @var $sng Searchable */
			$sng = singleton($class);
            
            $type = $sng->getElasticaType();
            if (isset($this->mappings[$type])) {
                // captured later
                continue;
            }

			$mapping = $sng->getElasticaMapping();
			$mapping->setType($index->getType($type));
			$mapping->send();
		}
        
        if ($this->mappings) {
            foreach ($this->mappings as $type => $fields) {
                $mapping = new Mapping();
                $mapping->setProperties($fields);
                $mapping->setParam('date_detection', false);
                
                $mapping->setType($index->getType($type));
                $mapping->send();
            }
        }
    }

	/**
	 * Re-indexes each record in the index.
	 */
	public function refresh($logFunc = null) {
		$index = $this->getIndex();
        if (!$logFunc) {
            $logFunc = function ($msg) {
                
            };
        }

		foreach ($this->getIndexedClasses() as $class) {
            $logFunc("Indexing items of type $class");
            $this->startBulkIndex();
			foreach ($class::get() as $record) {
                $logFunc("Indexing " . $record->Title);
				$this->index($record);
			}
            
            if (\Object::has_extension($class, 'Versioned')) {
                $live = \Versioned::get_by_stage($class, 'Live');
                foreach ($live as $liveRecord) {
                    $logFunc("Indexing Live record " . $liveRecord->Title);
                    $this->index($liveRecord, 'Live');
                }
            }
            $this->endBulkIndex();
		}
		
	}

	/**
	 * Gets the classes which are indexed (i.e. have the extension applied).
	 *
	 * @return array
	 */
	public function getIndexedClasses() {
		$classes = array();

		foreach (\ClassInfo::subclassesFor('DataObject') as $candidate) {
			if (singleton($candidate)->hasExtension('Symbiote\\Elastica\\Searchable')) {
				$classes[] = $candidate;
			}
		}

		return $classes;
	}
    
}
