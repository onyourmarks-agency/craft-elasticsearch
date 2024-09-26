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

namespace oym\elasticsearch\resources;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * AssetBundle used in the Control Panel
 */
class CpAssetBundle extends AssetBundle
{
    /**
     * Initializes the bundle.
     */
    public function init(): void
    {
        // define the path that your publishable resources live
        $this->sourcePath = '@oym/elasticsearch/resources/cp';

        // define the dependencies
        $this->depends = [
            CpAsset::class,
        ];

        // define the relative path to CSS/JS files that should be registered
        // with the page when this asset bundle is registered
        $this->js = [
            'js/utilities/reindex.js',
        ];

        $this->css = [
            'css/elastic-branding.css',
        ];

        parent::init();
    }
}
