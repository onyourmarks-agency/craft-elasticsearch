<?php

declare(strict_types=1);

/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 */

namespace oym\elasticsearch\events;

use yii\base\Event;

class ErrorEvent extends Event
{
    /** @var \Exception */
    public $exception;

    public function __construct(\Exception $exception, array $config = [])
    {
        parent::__construct($config);

        $this->exception = $exception;
    }
}
