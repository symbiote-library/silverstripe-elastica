<?php

namespace Symbiote\Elastica;

/**
 * @author marcus
 */
class ReindexItemsTask extends \BuildTask 
{
    protected $title = 'Reindex specific items in Elastic';

	protected $description = 'Reindexes specific items in Elastic';
    
    public function run($request) {
        if (!(\Permission::check('ADMIN') || \Director::is_cli())) {
            exit("Invalid");
        }
        
        $service = singleton('ElasticaService');
        
        $items = explode(',', $request->getVar('ids'));
        if (!count($items)) {
            return;
        }
        
        $baseType = $request->getVar('base') ? $request->getVar('base') : 'SiteTree';
        
        $recurse = $request->getVar('recurse') ? true : false;
        
        foreach ($items as $id) {
            $id = (int) $id;
            if (!$id) {
                continue;
            }
            \Versioned::reading_stage('Stage');
            $item = $baseType::get()->byID($id);
            if ($item) {
                $this->reindex($item, $recurse, $baseType, $service, 'Stage');
            }
            \Versioned::reading_stage('Live');
            $item = $baseType::get()->byID($id);
            if ($item) {
                $this->reindex($item, $recurse, $baseType, $service, 'Live');
            }
        }

        return;
                
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
    
    protected function reindex ($item, $recurse, $baseType, $service, $stage) {
        echo "Reindex $item->Title \n<br/>";
        $service->index($item, $stage);
        if ($recurse) {
            $children = $baseType::get()->filter('ParentID', $item->ID);
            if ($children) {
                foreach ($children as $child) {
                    $this->reindex($child, $recurse, $baseType, $service, $stage);
                }
            }
        }
    }

}
