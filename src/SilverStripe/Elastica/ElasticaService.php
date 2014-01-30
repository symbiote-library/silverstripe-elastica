<?php

namespace SilverStripe\Elastica;

use Elastica\Client;
use Elastica\Query;

/**
 * A service used to interact with elastic search.
 */
class ElasticaService {

	/**
	 * @var \Elastica\Document[]
	 */
	protected $buffer = array();

	/**
	 * @var bool controls whether indexing operations are buffered or not
	 */
	protected $buffered = false;

    /**
     * @var \Elastica\Client Elastica Client object
     */
	private $client;

    /**
     * @var string index name
     */
	private $index;

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
	 * @return ResultList
	 */
	public function search($query) {
		return new ResultList($this->getIndex(), Query::create($query));
	}

    /**
     * Ensure that the index is present
     */
    protected function ensureIndex()
    {
        $index = $this->getIndex();
        if (!$index->exists())
        {
            $index->create();
        }
    }

    /**
     * Ensure that there is a mapping present
     *
     * @param \Elastica\Type Type object
     * @param \DataObject Data record
     * @return \Elastica\Mapping Mapping object
     */
    protected function ensureMapping(\Elastica\Type $type, \DataObject $record)
    {
        try
        {
            $mapping = $type->getMapping();
        }
        catch(\Elastica\Exception\ResponseException $e)
        {
            $this->ensureIndex();
            $mapping = $record->getElasticaMapping();
            $type->setMapping($mapping);
        }
        return $mapping;
    }

	/**
	 * Either creates or updates a record in the index.
	 *
	 * @param Searchable $record
	 */
	public function index($record) {
		$document = $record->getElasticaDocument();
		$typeName = $record->getElasticaType();

		if ($this->buffered) {
			if (array_key_exists($typeName, $this->buffer)) {
				$this->buffer[$typeName][] = $document;
			} else {
				$this->buffer[$typeName] = array($document);
			}
		} else {
			$index = $this->getIndex();

            $type = $index->getType($typeName);

            $this->ensureMapping($type, $record);

            $type->addDocument($document);
			$index->refresh();
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
		$index = $this->getIndex();

		foreach ($this->buffer as $type => $documents) {
			$index->getType($type)->addDocuments($documents);
			$index->refresh();
		}

		$this->buffered = false;
		$this->buffer = array();
	}

	/**
	 * Deletes a record from the index.
	 *
	 * @param Searchable $record
	 */
	public function remove($record) {
		$index = $this->getIndex();
		$type = $index->getType($record->getElasticaType());

		$type->deleteDocument($record->getElasticaDocument());
	}

	/**
	 * Creates the index and the type mappings.
	 */
	public function define() {
		$index = $this->getIndex();

		# Recreate the index
        if ($index->exists()) {
            $index->delete();
		}
        $index->create();

		foreach ($this->getIndexedClasses() as $class) {
			/** @var $sng Searchable */
			$sng = singleton($class);

			$mapping = $sng->getElasticaMapping();
			$mapping->setType($index->getType($sng->getElasticaType()));
			$mapping->send();
		}
	}

    /**
     * Refresh a list of records in the index
     *
     * @param \DataList $records
     */
    protected function refreshRecords($records)
    {
        foreach ($records as $record) {
            if ($record->showRecordInSearch()) {
                $this->index($record);
            }
        }

    }

    /**
     * Get a List of all records by class. Get the "Live data" If the class has the "Versioned" extension
     *
     * @param string $class Class Name
     * @return \DataObject[] $records
     */
    protected function recordsByClassConsiderVersioned($class)
    {
        if ($class::has_extension("Versioned")) {
            $records = \Versioned::get_by_stage($class, 'Live');
        } else {
            $records = $class::get();
        }
        return $records->toArray();
    }

    /**
     * Refresh the records of a given class within the search index
     *
     * @param string $class Class Name
     */
    protected function refreshClass($class)
    {
        $records = $this->recordsByClassConsiderVersioned($class);

        if ($class::has_extension("Translatable")) {

            $original_locale = \Translatable::get_current_locale();
            $existing_languages = \Translatable::get_existing_content_languages($class);

            if (isset($existing_languages[$original_locale])) {
                unset($existing_languages[$original_locale]);
            }

            foreach($existing_languages as $locale => $langName) {
                \Translatable::set_current_locale($locale);
                $langRecords = $this->recordsByClassConsiderVersioned($class);
                foreach ($langRecords as $record)
                {
                    $records[] = $record;
                }
            }
            \Translatable::set_current_locale($original_locale);
        }

        $this->refreshRecords($records);
    }

	/**
	 * Re-indexes each record in the index.
	 */
	public function refresh() {
		$index = $this->getIndex();
		$this->startBulkIndex();

		foreach ($this->getIndexedClasses() as $class) {
            $this->refreshClass($class);
		}

		$this->endBulkIndex();
	}

	/**
	 * Gets the classes which are indexed (i.e. have the extension applied).
	 *
	 * @return array
	 */
	public function getIndexedClasses() {
		$classes = array();

		foreach (\ClassInfo::subclassesFor('DataObject') as $candidate) {
			if (singleton($candidate)->hasExtension('SilverStripe\\Elastica\\Searchable')) {
				$classes[] = $candidate;
			}
		}

		return $classes;
	}

}
