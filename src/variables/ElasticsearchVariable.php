<?php

declare(strict_types=1);

/**
 * Elasticsearch plugin for Craft CMS 5.x
 *
 * Bring the power of Elasticsearch to you Craft 5 CMS project
 *
 * Forked from la-haute-societe/craft-elasticsearch
 *
 * @link      https://www.lahautesociete.com
 */

namespace oym\elasticsearch\variables;

use oym\elasticsearch\Elasticsearch;
use oym\elasticsearch\records\ElasticsearchRecord;

/**
 * This Twig variable allows running searches from the frontend templates
 */
class ElasticsearchVariable
{
    /**
     * Execute the given `$query` in the Elasticsearch index
     *     {{ craft.elasticsearch.results(query) }}
     * @param string $query String to search for
     * @return ElasticsearchRecord[]
     * @throws \oym\elasticsearch\exceptions\IndexElementException
     */
    public function search($query): array
    {
        return Elasticsearch::getInstance()->service->search($query ?? '');
    }
}
