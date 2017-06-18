> ## **IMPORTANT**

> This module is no longer actively maintained, however, if you're interested in adopting it, please let us know!

SilverStripe Elastica Module
============================

Provides elastic search integration for SilverStripe DataObjects using Elastica.

Usage
-----

The first step is to configure the Elastic Search service. To do this, the configuration system
is used. The simplest default configuration is:

    Injector:
      Symbiote\Elastica\ElasticaService:
        constructor:
          - %$Elastica\Client
          - index-name-to-use

You can then use the `Symbiote\Elastica\Searchable` extension to add searching functionality
to your data objects. Elastic search can then be interacted with using the
`Symbiote\Elastica\ElasticService` class.
