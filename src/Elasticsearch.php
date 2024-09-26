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

namespace oym\elasticsearch;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\commerce\elements\Product;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\errors\MissingComponentException;
use craft\events\ModelEvent;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\ElementHelper;
use craft\models\Section;
use craft\queue\Queue;
use craft\services\Plugins;
use craft\services\Utilities;
use craft\web\Application;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use oym\elasticsearch\exceptions\IndexElementException;
use oym\elasticsearch\models\SettingsModel;
use oym\elasticsearch\services\ElasticsearchService;
use oym\elasticsearch\services\ElementIndexerService;
use oym\elasticsearch\services\IndexManagementService;
use oym\elasticsearch\services\ReindexQueueManagementService;
use oym\elasticsearch\utilities\RefreshElasticsearchIndexUtility;
use oym\elasticsearch\variables\ElasticsearchVariable;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\debug\Module as DebugModule;
use yii\elasticsearch\Connection;
use yii\elasticsearch\DebugPanel;
use yii\elasticsearch\Exception;
use yii\queue\ExecEvent;
use yii\queue\Queue as QueueAlias;

/**
 * @property  services\ElasticsearchService          service
 * @property  services\ReindexQueueManagementService reindexQueueManagementService
 * @property  services\ElementIndexerService         $elementIndexerService
 * @property  services\IndexManagementService        $indexManagementService
 * @property  SettingsModel                          settings
 * @property  Connection                             elasticsearch
 * @method    SettingsModel                          getSettings()
 */
class Elasticsearch extends Plugin
{
    public const string EVENT_ERROR_NO_ATTACHMENT_PROCESSOR = 'errorNoAttachmentProcessor';
    public const string PLUGIN_HANDLE = 'elasticsearch';

    public bool $hasCpSettings = true;

    /**
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();
        $isCommerceEnabled = $this->isCommerceEnabled();
        $isDigitalProductsEnabled = $this->isDigitalProductsEnabled();

        $this->setComponents(
            [
                'service'                       => ElasticsearchService::class,
                'reindexQueueManagementService' => ReindexQueueManagementService::class,
                'elementIndexerService'         => ElementIndexerService::class,
                'indexManagementService'        => IndexManagementService::class,
            ],
        );

        $this->initializeElasticConnector();

        // Add console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'oym\elasticsearch\console\controllers';
        }

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            // Remove entry from the index upon deletion
            Event::on(
                Entry::class,
                Element::EVENT_AFTER_DELETE,
                function (Event $event) {
                    /** @var entry $entry */
                    $entry = $event->sender;
                    try {
                        $this->elementIndexerService->deleteElement($entry);
                    } catch (Exception $e) {
                        // Noop, the element must have already been deleted
                    }
                },
            );

            // Index entry, asset & products upon save (creation or update)
            Event::on(Entry::class, Element::EVENT_AFTER_SAVE, [$this, 'onElementSaved']);
            Event::on(Asset::class, Element::EVENT_AFTER_SAVE, [$this, 'onElementSaved']);
            if ($isCommerceEnabled) {
                Event::on(Product::class, Element::EVENT_AFTER_SAVE, [$this, 'onElementSaved']);

                if ($isDigitalProductsEnabled) {
                    Event::on(DigitalProduct::class, Element::EVENT_AFTER_SAVE, [$this, 'onElementSaved']);
                }
            }

            // Re-index all entries when plugin settings are saved
            Event::on(
                Plugins::class,
                Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS,
                function (PluginEvent $event) {
                    if ($event->plugin === $this) {
                        $this->onPluginSettingsSaved();
                    }
                },
            );

            // On reindex job success, remove its id from the cache (cache is used to keep track of reindex jobs and clear those having failed before reindexing all entries)
            Event::on(
                Queue::class,
                QueueAlias::EVENT_AFTER_EXEC,
                function (ExecEvent $event) {
                    $this->reindexQueueManagementService->removeJob($event->id);
                },
            );

            // Register the plugin's CP utility
            Event::on(
                Utilities::class,
                Utilities::EVENT_REGISTER_UTILITIES,
                static function (RegisterComponentTypesEvent $event) {
                    $event->types[] = RefreshElasticsearchIndexUtility::class;
                },
            );

            // Register our CP routes
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_CP_URL_RULES,
                static function (RegisterUrlRulesEvent $event) {
                    $event->rules['elasticsearch/cp/test-connection'] = 'elasticsearch/cp/test-connection';
                    $event->rules['elasticsearch/cp/reindex-perform-action'] = 'elasticsearch/cp/reindex-perform-action';
                },
            );

            // Display a flash message if the ingest attachment plugin isn't activated on the Elasticsearch instance
            Event::on(
                self::class,
                self::EVENT_ERROR_NO_ATTACHMENT_PROCESSOR,
                static function () {
                    $application = Craft::$app;
                    if ($application instanceof \yii\web\Application) {
                        $application->getSession()->setError('The ingest-attachment plugin seems to be missing on your Elasticsearch instance.');
                    }
                },
            );
        }

        // Add the Elasticsearch panel to the Yii debug bar
        Event::on(
            Application::class,
            Application::EVENT_BEFORE_REQUEST,
            static function () {
                /** @var DebugModule|null $debugModule */
                $debugModule = Craft::$app->getModule('debug');
                if ($debugModule) {
                    $debugModule->panels['elasticsearch'] = new DebugPanel(
                        [
                            'id'     => 'elasticsearch',
                            'module' => $debugModule,
                        ],
                    );
                }
            },
        );

        // Register variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('elasticsearch', ElasticsearchVariable::class);
            },
        );

        // Register our site routes (used by the console commands to reindex entries)
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function (RegisterUrlRulesEvent $event) {
                $event->rules['elasticsearch/get-all-elements'] = 'elasticsearch/site/get-all-elements';
                $event->rules['elasticsearch/reindex-all'] = 'elasticsearch/site/reindex-all';
                $event->rules['elasticsearch/reindex-element'] = 'elasticsearch/site/reindex-element';
            },
        );

        Craft::info("{$this->name} plugin loaded", __METHOD__);
    }


    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return SettingsModel
     */
    protected function createSettingsModel(): SettingsModel
    {
        return new SettingsModel();
    }

    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     */
    protected function settingsHtml(): string
    {
        // Get and pre-validate the settings
        $settings = $this->getSettings();
        //$settings->validate();

        // Get the settings that are being defined by the config file
        $overrides = Craft::$app->getConfig()->getConfigFromFile(strtolower($this->handle));

        $sections = ArrayHelper::map(
            Craft::$app->entries->getAllSections(),
            'id',
            static fn (Section $section): array => [
                    'label' => Craft::t('site', $section->name),
                    'types' => ArrayHelper::map(
                        $section->getEntryTypes(),
                        'id',
                        static fn ($section): array => ['label' => Craft::t('site', $section->name)],
                    ),
                ],
        );

        return Craft::$app->view->renderTemplate(
            'elasticsearch/cp/settings',
            [
                'settings'  => $settings,
                'overrides' => array_keys($overrides),
                'sections'  => $sections,
            ],
        );
    }

    public function beforeSaveSettings(): bool
    {
        $settings = $this->getSettings();
        $settings->elasticsearchComponentConfig = [];
        return parent::beforeSaveSettings();
    }

    /**
     * @return Connection
     * @throws \yii\base\InvalidConfigException
     */
    public static function getConnection(): Connection
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        /** @var Connection $connection */
        $connection = Craft::$app->get(self::PLUGIN_HANDLE);

        return $connection;
    }

    /**
     * Initialize the Elasticsearch connector
     * @param SettingsModel|null $settings
     * @throws \yii\base\InvalidConfigException If the configuration passed to the yii2-elasticsearch module is invalid
     */
    public function initializeElasticConnector(SettingsModel $settings = null): void
    {
        if (!$settings) {
            $settings = $this->getSettings();
        }

        if (!empty($settings->elasticsearchComponentConfig)) {
            $definition = $settings->elasticsearchComponentConfig;
        } else {
            $protocol = parse_url($settings->elasticsearchEndpoint, PHP_URL_SCHEME);
            $endpointUrlWithoutProtocol = preg_replace("#^$protocol(?:://)?#", '', $settings->elasticsearchEndpoint);

            $definition = [
                'connectionTimeout' => 10,
                'autodetectCluster' => false,
                'nodes'             => [
                    [
                        'protocol'     => $protocol ?? 'http',
                        'http_address' => $endpointUrlWithoutProtocol,
                        'http'         => ['publish_address' => $settings->elasticsearchEndpoint],
                    ],
                ],
            ];

            if ($settings->isAuthEnabled) {
                $definition['auth'] = [
                    'username' => $settings->username,
                    'password' => $settings->password,
                ];
            }
        }

        $definition['class'] = Connection::class;

        // Fix nodes. When cluster auto detection is disabled, the Elasticsearch component crashes when closing connections…
        array_walk(
            $definition['nodes'],
            static function (&$node) {
                if (!isset($node['http'])) {
                    $node['http'] = [];
                }

                if (!isset($node['http']['publish_address'])) {
                    $node['http']['publish_address'] = sprintf(
                        '%s://%s',
                        $node['protocol'] ?? 'http',
                        $node['http_address'],
                    );
                }
            },
        );

        /** @noinspection PhpUnhandledExceptionInspection Can't happen since a valid config array is passed */
        Craft::$app->set(self::PLUGIN_HANDLE, $definition);
    }

    /**
     * Check for presence of Craft Commerce Plugin
     * @return bool
     */
    public function isCommerceEnabled(): bool
    {
        return class_exists(\craft\commerce\Plugin::class);
    }

    /**
     * Check for presence of Craft Digital Products Plugin
     * @return bool
     */
    public function isDigitalProductsEnabled(): bool
    {
        return class_exists(\craft\digitalproducts\Plugin::class);
    }

    public function onElementSaved(ModelEvent $event): void
    {
        /** @var Element $element */
        $element = $event->sender;

        // Handle drafts and revisions for Craft 3.2 and upper
        $notDraftOrRevision = true;
        $schemaVersion = Craft::$app->getInstalledSchemaVersion();
        if (version_compare($schemaVersion, '3.2.0', '>=')) {
            $notDraftOrRevision = !ElementHelper::isDraftOrRevision($element);
        }

        if ($notDraftOrRevision) {
            if ($element->enabled) {
                $this->reindexQueueManagementService->enqueueJob($element->id, $element->siteId, get_class($element));
            } else {
                try {
                    $this->elementIndexerService->deleteElement($element);
                } catch (Exception $e) {
                    // Noop, the element must have already been deleted
                }
            }
        }
    }

    /**
     * @throws MissingComponentException
     * @throws InvalidConfigException
     */
    protected function onPluginSettingsSaved(): void
    {
        $this->initializeElasticConnector();

        Craft::debug('Elasticsearch plugin settings saved => re-index all elements', __METHOD__);
        try {
            $this->indexManagementService->recreateIndexesForAllSites();

            // Remove previous reindexing jobs as all elements will be reindexed anyway
            $this->reindexQueueManagementService->clearJobs();
            $this->reindexQueueManagementService->enqueueReindexJobs($this->service->getIndexableElementModels());
        } catch (IndexElementException $e) {
            /** @noinspection PhpUnhandledExceptionInspection This method should only be called in a web context so Craft::$app->getSession() will never throw */
            Craft::$app->getSession()->setError($e->getMessage());
        }
    }
}
