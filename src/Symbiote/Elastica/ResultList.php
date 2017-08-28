<?php

namespace Symbiote\Elastica;

use Elastica\Query;

/**
 * A list wrapper around the results from a query. Note that not all operations are implemented.
 */
class ResultList extends \ViewableData implements \SS_Limitable {

    /**
     *
     * @var \Elastica\Index
     */
	private $index;

    /**
     *
     * @var \Elastica\Query
     */
	private $query;


    protected $dataObjects;

    protected $totalResults  = 0;

    /**
     *
     */
    protected $results;

    /**
     *
     * @var \Elastica\ResultSet
     */
    protected $resultSet;

	public function __construct(/*Index */$index, Query $query) {
		$this->index = $index;
		$this->query = $query;
	}

	public function __clone() {
		$this->query = clone $this->query;
	}

	/**
	 * @return \Elastica\Index
	 */
	public function getIndex() {
		return $this->index;
	}

	/**
	 * @return \Elastica\Query
	 */
	public function getQuery() {
		return $this->query;
	}

	/**
	 * @return \Elastica\ResultSet
	 */
	public function getResultSet() {
        if (!$this->resultSet) {
            $this->resultSet = $this->index->search($this->query);
        }
        return $this->resultSet;
	}

	public function getIterator() {
		return new \ArrayIterator($this->toArray());
	}

	public function limit($limit, $offset = 0) {
		$list = clone $this;

		$list->getQuery()->setSize($limit);
		$list->getQuery()->setFrom($offset);

		return $list;
	}

    public function getTotalResults() {
		return $this->getResultSet()->getTotalHits();
	}

    public function getTimeTaken() {
        return $this->getResultSet()->getTotalTime();
    }

    public function getAggregations() {
        return $this->getResultSet()->getAggregations();
    }

	/**
	 *	The paginated result set that is rendered onto the search page.
	 *
	 *	@return PaginatedList
	 */
    public function getDataObjects($limit = 0, $start = 0) {

        $pagination = \PaginatedList::create($this->toArrayList())
			->setPageLength($limit)
			->setPageStart($start)
			->setTotalItems($this->getTotalResults())
			->setLimitItems(false);
		return $pagination;
    }

    /**
	 * Converts results of type {@link \Elastica\Result}
	 * into their respective {@link DataObject} counterparts.
	 *
	 * @return array DataObject[]
	 */
	public function toArray($evaluatePermissions = false) {
        if ($this->dataObjects) {
			return $this->dataObjects;
        }

		$result = array();

		/** @var $found \Elastica\Result[] */
		$found = $this->getResultSet();
		$needed = array();
		$retrieved = array();

		foreach ($found->getResults() as $item) {
            $data = $item->getData();

            $type = isset($data['ClassName']) ? $data['ClassName'] : $item->getType();
            $bits = explode('_', $item->getId());
            $id = isset($data['ID']) ? $data['ID'] : $item->getId();

            if (count($bits) == 3) {
                list($type, $id, $stage) = $bits;
            } else if (count($bits) == 2) {
                list($type, $id) = $bits;
                $stage = \Versioned::current_stage();
            } else {
                $stage = \Versioned::current_stage();
            }

            if (!$type || !$id) {
                error_log("Invalid elastic document ID {$item->getId()}");
                continue;
            }

			// a double sanity check for the stage here.
			if ($currentStage = \Versioned::current_stage()) {
				if ($currentStage != $stage) {
					continue;
				}
			}

            if (class_exists($type)) {
                $object = \DataObject::get_by_id($type, $id);
            } else {
                $object = \ArrayData::create($item->getSource());
            }
			

            if ($object) {
                // check that the user has permission
                if ($item->getScore()) {
                    $object->SearchScore = $item->getScore();
                }

                $canAdd = true;
                if ($evaluatePermissions) {
                    // check if we've got a way of evaluating perms
                    if ($object->hasMethod('canView')) {
                        $canAdd = $object->canView();
                    }
                }

                if (!$evaluatePermissions || $canAdd) {
                    if ($object->hasMethod('canShowInSearch')) {
                        if ($object->canShowInSearch()) {
                            $result[] = $object;
                        }
                    } else {
                        $result[] = $object;
                    }
                }
            } else {
                error_log("Object {$item->getId()} is no longer in the system");
            }
		}
//        
//        $this->totalResults = $documents->numFound;
//				
//				// update the dos with stats about this query
//				
//				$this->dataObjects = PaginatedList::create($this->dataObjects);
//				
//				$this->dataObjects->setPageLength($this->queryParameters->limit)
//						->setPageStart($documents->start)
//						->setTotalItems($documents->numFound)
//						->setLimitItems(false);

//        if (!array_key_exists($type, $needed)) {
//            $needed[$type] = array($item->getId());
//            $retrieved[$type] = array();
//        } else {
//            $needed[$type][] = $item->getId();
//        }
//
//		foreach ($needed as $class => $ids) {
//			foreach ($class::get()->byIDs($ids) as $record) {
//				$retrieved[$class][$record->ID] = $record;
//			}
//		}
//
//		foreach ($found as $item) {
//			// Safeguards against indexed items which might no longer be in the DB
//			if(array_key_exists($item->getId(), $retrieved[$item->getType()])) {
//				$result[] = $retrieved[$item->getType()][$item->getId()];
//			}
//		}

        $this->dataObjects = $result;
		return $result;
	}

	public function toArrayList() {
		return new \ArrayList($this->toArray());
	}

	public function toNestedArray() {
		$result = array();

		foreach ($this as $record) {
			$result[] = $record->toMap();
		}

		return $result;
	}

	public function first() {
		// TODO
	}

	public function last() {
		// TODO: Implement last() method.
	}

	public function map($key = 'ID', $title = 'Title') {
		return $this->toArrayList()->map($key, $title);
	}

	public function column($col = 'ID') {
		if($col == 'ID') {
			$ids = array();

			foreach ($this->getResultSet()->getResults() as $result) {
				$ids[] = $result->getId();
			}

			return $ids;
		} else {
			return $this->toArrayList()->column($col);
		}
	}

	public function each($callback) {
		return $this->toArrayList()->each($callback);
	}

	public function count() {
		return count($this->toArray());
	}

	/**
	 * @ignore
	 */
	public function offsetExists($offset) {
		throw new \Exception();
	}

	/**
	 * @ignore
	 */
	public function offsetGet($offset) {
		throw new \Exception();
	}

	/**
	 * @ignore
	 */
	public function offsetSet($offset, $value) {
		throw new \Exception();
	}

	/**
	 * @ignore
	 */
	public function offsetUnset($offset) {
		throw new \Exception();
	}

	/**
	 * @ignore
	 */
	public function add($item) {
		throw new \Exception();
	}

	/**
	 * @ignore
	 */
	public function remove($item) {
		throw new \Exception();
	}

	/**
	 * @ignore
	 */
	public function find($key, $value) {
		throw new \Exception();
	}

}