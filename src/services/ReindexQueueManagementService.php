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

namespace oym\elasticsearch\services;

use Craft;
use craft\base\Component;
use oym\elasticsearch\Elasticsearch;
use oym\elasticsearch\jobs\IndexElementJob;
use oym\elasticsearch\models\IndexableElementModel;

/**
 * Service used to manage the reindex job queue.
 * It allows clearing failed reindexing jobs before reindexing all entries.
 * @property array $cache
 */
class ReindexQueueManagementService extends Component
{
    const string CACHE_KEY = Elasticsearch::PLUGIN_HANDLE . '_reindex_jobs';

    /**
     * Add reindex job for the given entries
     * @param IndexableElementModel[] $indexableElementModels
     */
    public function enqueueReindexJobs(array $indexableElementModels): void
    {
        $jobIds = [];
        foreach ($indexableElementModels as $model) {
            $jobIds[] = Craft::$app->getQueue()->push(new IndexElementJob($model->toArray()));
        }

        $jobIds = array_unique(array_merge($jobIds, $this->getCache()));
        Craft::$app->getCache()->set(self::CACHE_KEY, $jobIds, 24 * 60 * 60);
    }

    /**
     * Remove all jobs from the queue
     */
    public function clearJobs(): void
    {
        $jobIds = $this->getCache();
        foreach ($jobIds as $jobId) {
            $this->removeJobFromQueue($jobId);
        }

        $cacheService = Craft::$app->getCache();
        $cacheService->delete(self::CACHE_KEY);
    }

    /**
     * Remove a job from the queue
     * @param string $id The id of the job to remove
     */
    public function removeJob(string $id): void
    {
        $this->removeJobFromQueue($id);
        $this->removeJobIdFromCache($id);
    }

    public function enqueueJob(int $entryId, int $siteId, string $type): void
    {
        $job = new IndexElementJob(
            [
                'siteId'    => $siteId,
                'elementId' => $entryId,
                'type'      => $type,
            ],
        );

        $jobId = Craft::$app->queue->push($job);

        $this->addJobIdToCache($jobId);
    }

    /**
     * Remove a job from the queue. This should work with any cache backend.
     * This does NOT remove the job id from the cache
     * @param string $id The id of the job to remove
     */
    protected function removeJobFromQueue(string $id): void
    {
        $queueService = Craft::$app->getQueue();
        $methodName = $queueService instanceof \yii\queue\db\Queue ? 'remove' : 'release';
        $queueService->$methodName($id);
    }

    /**
     * Add a job id to the cache
     * @param string $id The job id to add to the cache
     */
    protected function addJobIdToCache(string $id): void
    {
        $jobIds = $this->getCache();
        $jobIds[] = $id;

        Craft::$app->getCache()->set(self::CACHE_KEY, $jobIds);
    }

    /**
     * Remove a job id from the cache
     * @param string $id The job id to remove from the cache
     */
    protected function removeJobIdFromCache(string $id): void
    {
        $jobIds = array_diff($this->getCache(), [$id]);

        Craft::$app->getCache()->set(self::CACHE_KEY, $jobIds);
    }

    /**
     * Get the job ids from the cache
     * @return array An array of job ids
     */
    protected function getCache(): array
    {
        $cache = Craft::$app->getCache();
        $jobIds = $cache->get(self::CACHE_KEY);

        if ($jobIds === false || !is_array($jobIds)) {
            $jobIds = [];
        }

        return $jobIds;
    }
}
