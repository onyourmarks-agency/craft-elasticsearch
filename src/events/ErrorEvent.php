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

namespace oym\elasticsearch\events;

use yii\base\Event;

class ErrorEvent extends Event
{
    public \Exception $exception;

    public function __construct(\Exception $exception, array $config = [])
    {
        parent::__construct($config);

        $this->exception = $exception;
    }
}
