<?php
/**
 * @link      http://www.lahautesociete.com
 * @copyright Copyright (c) 2019 La Haute Société
 */

namespace oym\elasticsearch\migrations;

use craft\db\Migration;
use oym\elasticsearch\Elasticsearch;
use oym\elasticsearch\Elasticsearch as ElasticsearchPlugin;

/**
 * UpdateSchema class
 *
 * @author albanjubert
 **/
class m190602_000000_recreate_indexes extends Migration
{
    /** @var ElasticsearchPlugin */
    public $plugin;

    public function init(): void
    {
        parent::init();

        $this->plugin = ElasticsearchPlugin::getInstance();
    }

    public function safeUp()
    {
        // Indexes need to be updated to take new fields in consideration
        $this->_rebuildElasticsearchIndexes();
    }

    public function safeDown()
    {
        $this->_rebuildElasticsearchIndexes();
    }

    private function _rebuildElasticsearchIndexes()
    {
        $this->plugin->indexManagementService->recreateIndexesForAllSites();
        $this->plugin->reindexQueueManagementService->enqueueReindexJobs($this->plugin->service->getIndexableElementModels());
    }
}
