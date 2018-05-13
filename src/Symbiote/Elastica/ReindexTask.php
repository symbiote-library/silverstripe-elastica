<?php

namespace Symbiote\Elastica;

use SilverStripe\Security\Permission;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;

/**
 * Defines and refreshes the elastic search index.
 */
class ReindexTask extends BuildTask
{

    protected $title = 'Elastic Search Reindex';

    protected $description = 'Refreshes the elastic search index';

    /**
     * @var ElasticaService
     */
    private $service;

    public function __construct(ElasticaService $service)
    {
        $this->service = $service;
    }

    public function run($request)
    {
        if (!(Permission::check('ADMIN') || Director::is_cli())) {
            exit("Invalid");
        }
        
        $message = function ($content) {
            print(Director::is_cli() ? "$content\n" : "<p>$content</p>");
        };
        
        $message("Specify 'rebuild' to delete the index first, and 'reindex' to re-index content items");
        
        if ($request->getVar('rebuild')) {
            $this->service->getIndex()->delete();
        }

        $message('Defining the mappings (if not already)');
        $this->service->define();
        
        if ($request->getVar('reindex')) {
            $message('Refreshing the index');
            try {
                $this->service->refresh($message);
            } catch (\Exception $ex) {
                $message("Some failures detected when indexing " . $ex->getMessage());
            }
        }
    }
}
