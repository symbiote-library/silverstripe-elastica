<?php

namespace SilverStripe\Elastica;

use Elastica\Client;
use Elastica\Query;

/**
 * A service used to interact with elastic search.
 */
class ElasticaService {

	private $client;
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
	 * Either creates or updates a record in the index.
	 *
	 * @param Searchable $record
	 */
	public function index($record) {
		$index = $this->getIndex();
		$type = $index->getType($record->getElasticaType());

		$type->addDocument($record->getElasticaDocument());
		$index->refresh();
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

		if (!$index->exists()) {
			$index->create();
		}

		foreach ($this->getIndexedClasses() as $class) {
			/** @var $sng Searchable */
			$sng = singleton($class);

			$mapping = $sng->getElasticaMapping();
			$mapping->setType($index->getType($sng->getElasticaType()));
			$mapping->send();
		}
	}

	/**
	 * Re-indexes each record in the index.
	 */
	public function refresh() {
		$index = $this->getIndex();

		foreach ($this->getIndexedClasses() as $class) {
			$sng = singleton($class);
			$type = $index->getType($sng->getElasticaType());

			foreach ($class::get() as $record) {
				$type->addDocument($record->getElasticaDocument());
			}

			$index->refresh();
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
			if (singleton($candidate)->hasExtension('SilverStripe\\Elastica\\Searchable')) {
				$classes[] = $candidate;
			}
		}

		return $classes;
	}

}
