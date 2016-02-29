<?php

namespace SilverStripe\Elastica;

use Elastica\Document;
use Elastica\Type\Mapping;

/**
 * Adds elastic search integration to a data object.
 */
class Searchable extends \DataExtension {

	public static $mappings = array(
		'Boolean'           => 'boolean',
		'Decimal'           => 'double',
		'Double'            => 'double',
		'Enum'              => 'string',
		'Float'             => 'float',
		'HTMLText'          => 'string',
		'HTMLVarchar'       => 'string',
		'Int'               => 'integer',
		'SS_Datetime'       => 'date',
		'Text'              => 'string',
		'Varchar'           => 'string',
		'Year'              => 'integer',
        'MultiValueField'   => 'string',
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
		return $this->ownerBaseClass;
	}

	/**
	 * Gets an array of elastic field definitions.
	 *
	 * @return array
	 */
	public function getElasticaFields() {
		$db = \DataObject::database_fields(get_class($this->owner));
		$fields = $this->owner->searchableFields();
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
        
        $result['LastEdited'] = array('type' => 'date');
        $result['Created'] = array('type' => 'date');
        $result['ID']       = array('type' => 'integer');

        $result['ParentID'] = array('type' => 'integer');
        $result['Sort'] = array('type' => 'integer');
        
        $result['Name'] = array('type' => 'string');
        $result['MenuTitle'] = array('type' => 'string');
        $result['ShowInSearch'] = array('type' => 'boolean');
        
        $result['ClassNameHierarchy'] = array('type' => 'string');
        
		return $result;
	}

	/**
	 * @return \Elastica\Type\Mapping
	 */
	public function getElasticaMapping() {
		$mapping = new Mapping();
		$mapping->setProperties($this->getElasticaFields());

		return $mapping;
	}

	public function getElasticaDocument($stage = 'Stage') {
		$fields = array();

		foreach ($this->owner->getElasticaFields() as $field => $config) {
            if ($this->owner->hasField($field)) {
                $fields[$field] = $this->owner->$field;
            }
		}
        
        if ($this->owner->hasExtension('Versioned')) {
            // add in the specific stage(s) 
            $fields['SS_Stage'] = array($stage);
        } else {
            $fields['SS_Stage'] = array('Live', 'Stage');
        }
        
        if ($this->owner->hasExtension('Hierarchy') || $this->owner->hasField('ParentID')) {
            $fields['ParentsHierarchy'] = $this->getParentsHierarchyField();
        }
        
        if (!isset($fields['ClassNameHierarchy'])) {
            $classes = array_values(\ClassInfo::ancestry($this->owner->class));
            if (!$classes) {
                $classes = array($this->owner->class);
            }
            $fields['ClassNameHierarchy'] = $classes;
        }

        $id = get_class($this->owner) . '_' . $this->owner->ID . '_' . $stage;
        
        $this->owner->extend('updateSearchableData', $fields);
        
		return new Document($id, $fields);
	}
    
    /**
	 * Get a solr field representing the parents hierarchy (if applicable)
	 * 
	 * @param type $dataObject 
	 */
	protected function getParentsHierarchyField() {
		// see if we've got Parent values
        $parents = array();

        $parent = $this->owner;
        while ($parent && $parent->ParentID) {
            $parents[] = $parent->ParentID;
            $parent = $parent->Parent();
            // fix for odd behaviour - in some instance a node is being assigned as its own parent. 
            if ($parent->ParentID == $parent->ID) {
                $parent = null;
            }
        }
        return $parents;
	}

	/**
	 * Updates the record in the search index.
	 */
	public function onAfterWrite() {
        $stage = \Versioned::current_stage();
        
		$this->service->index($this->owner, $stage);
	}

	/**
	 * Removes the record from the search index.
	 */
	public function onAfterDelete() {
        $stage = \Versioned::current_stage();
		$this->service->remove($this->owner, $stage);
	}
    
    public function onAfterPublish() {
        $this->service->index($this->owner, 'Live');
    }
    
    /**
	 * If unpublished, we delete from the index then reindex the 'stage' version of the 
	 * content
	 *
	 * @return 
	 */
	function onAfterUnpublish() {
		$this->service->remove($this->owner, 'Live');
        $this->service->index($this->owner, 'Stage');
	}
}
