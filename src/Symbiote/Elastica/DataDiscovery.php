<?php

namespace Symbiote\Elastica;

/**
 * 
 *
 * @author marcus
 */
class DataDiscovery extends \DataExtension
{
    //put your code here
    private static $db = [
        'BoostTerms' => 'MultiValueField',
    ];

    public function updateCMSFields(\FieldList $fields)
    {
        $fields->addFieldsToTab('Root.Tagging', $mvf = \MultiValueTextField::create('BoostTerms', 'Boost for these keywords'));
        $mvf->setRightTitle("Enter the word 'important' to boost this item in any search it appears in");

    }


    /**
     * Sets appropriate mappings for fields that need to be subsequently faceted upon
     * @param type $mappings
     */
    public function updateElasticMappings($mappings)
    {
        $mappings['BoostTerms'] = ['type' => 'keyword'];

        $mappings['Categories'] = ['type' => 'keyword'];
        $mappings['Keywords'] = ['type' => 'text'];
        $mappings['Tags'] = ['type' => 'keyword'];

        if ($this->owner instanceof \SiteTree) {
            // store the SS_URL for consistency
            $mappings['SS_URL'] = ['type' => 'text'];
        }
    }

    public function updateSearchableData($fieldValues)
    {
        $fieldValues['BoostTerms']  = $this->owner->BoostTerms->getValues();

        // expects taxonomy terms here...
        if ($this->owner->hasMethod('Terms')) {
            $categories = $this->owner->Terms()->column('Name');

            $currentCats = isset($fieldValues['Categories']) ? $fieldValues['Categories'] : [];

            $fieldValues['Categories'] = array_merge($currentCats, $categories);
            $fieldValues['Keywords'] = implode(' ', $categories);
        }


        if ($this->owner instanceof \SiteTree) {
            // store the SS_URL for consistency
            $fieldValues['SS_URL'] = $this->owner->RelativeLink();
        }
    }
}