<?php

declare(strict_types=1);

/**
 * @link http://www.lahautesociete.com
 */

namespace oym\elasticsearch\events;

use yii\base\Event;

/**
 * SearchEvent class
 **/
class SearchEvent extends Event
{
    public $query;
}
