<?php

namespace SilverStripe\Elastica;

use Elastica\Document;
use Elastica\Type\Mapping;

/**
 * Adds elastic search integration to a data object.
 */
class Searchable extends \DataExtension {

	public static $mappings = array(
		'Boolean'     => 'boolean',
		'Decimal'     => 'double',
		'Double'      => 'double',
		'Enum'        => 'string',
		'Float'       => 'float',
		'HTMLText'    => 'string',
		'HTMLVarchar' => 'string',
		'Int'         => 'integer',
		'SS_Datetime' => 'date',
		'Text'        => 'string',
		'Varchar'     => 'string',
		'Year'        => 'integer',
        'Date'        => 'date',
	);

	private $service;

	public function __construct(ElasticaService $service) {
		$this->service = $service;
		parent::__construct();
	}

	/**
	 * @return string
	 */
	public function getElasticaType() {
		return get_class($this->owner);
    }

	/**
	 * Gets an array of elastic field definitions.
	 *
	 * @return array
	 */
	public function getElasticaFields() {
        $db = $this->owner->db();
		$fields = $this->getAllSearchableFields();
		$result = array();

		foreach ($fields as $name => $params) {
			$type = null;
			$spec = array();

			if (array_key_exists($name, $db)) {
				$class = $db[$name];

				if (($pos = strpos($class, '('))) {
					$class = substr($class, 0, $pos);
				}

				if (array_key_exists($class, self::$mappings)) {
					$spec['type'] = self::$mappings[$class];
				}
			}

			$result[$name] = $spec;
		}

		return $result;
	}

	/**
	 * @return \Elastica\Type\Mapping
	 */
	public function getElasticaMapping() {
		$mapping = new Mapping();

        $fields = $this->getElasticaFields();

		$mapping->setProperties($fields);

		return $mapping;
	}

	public function getElasticaDocument() {
		$fields = array();

		foreach ($this->getElasticaFields() as $field => $config) {
			$fields[$field] = $this->owner->$field;
		}

		return new Document($this->owner->ID, $fields);
	}

	/**
	 * Updates the record in the search index.
	 */
	public function onAfterWrite() {
		$this->service->index($this->owner);
	}

	/**
	 * Removes the record from the search index.
	 */
	public function onAfterDelete() {
		$this->service->remove($this->owner);
	}

    /**
     * Return all of the searchable fields defined in $this->owner::$searchable_fields and all the parent classes.
     *
     * @return array searchable fields
     */
    public function getAllSearchableFields()
    {
        $fields = \Config::inst()->get(get_class($this->owner), 'searchable_fields');
        $labels = $this->owner->fieldLabels();

        // fallback to default method
        if(!$fields) {
            return $this->owner->searchableFields();
        }

        // Copied from DataObject::searchableFields() as there is no separate accessible method

        // rewrite array, if it is using shorthand syntax
        $rewrite = array();
        foreach($fields as $name => $specOrName) {
            $identifer = (is_int($name)) ? $specOrName : $name;

            if(is_int($name)) {
                // Format: array('MyFieldName')
                $rewrite[$identifer] = array();
            } elseif(is_array($specOrName)) {
                // Format: array('MyFieldName' => array(
                //   'filter => 'ExactMatchFilter',
                //   'field' => 'NumericField', // optional
                //   'title' => 'My Title', // optiona.
                // ))
                $rewrite[$identifer] = array_merge(
                    array('filter' => $this->owner->relObject($identifer)->stat('default_search_filter_class')),
                    (array)$specOrName
                );
            } else {
                // Format: array('MyFieldName' => 'ExactMatchFilter')
                $rewrite[$identifer] = array(
                    'filter' => $specOrName,
                );
            }
            if(!isset($rewrite[$identifer]['title'])) {
                $rewrite[$identifer]['title'] = (isset($labels[$identifer]))
                    ? $labels[$identifer] : \FormField::name_to_label($identifer);
            }
            if(!isset($rewrite[$identifer]['filter'])) {
                $rewrite[$identifer]['filter'] = 'PartialMatchFilter';
            }
        }

        return $rewrite;
    }

}
