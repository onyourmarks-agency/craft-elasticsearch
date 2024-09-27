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

namespace oym\elasticsearch\records;

use Craft;
use craft\base\Element;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use oym\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use oym\elasticsearch\events\SearchEvent;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\db\StaleObjectException;
use yii\elasticsearch\ActiveRecord;
use yii\elasticsearch\Connection;
use yii\elasticsearch\Exception;
use yii\helpers\Json;
use yii\helpers\VarDumper;

/**
 * @property string       $title
 * @property string       $url
 * @property string       $elementHandle
 * @property object|array $content
 * @property string       $postDate
 * @property boolean      $noPostDate
 * @property string       $expiryDate
 * @property boolean      $noExpiryDate
 */
class ElasticsearchRecord extends ActiveRecord
{
    const string EVENT_BEFORE_CREATE_INDEX = 'beforeCreateIndex';
    const string EVENT_BEFORE_SAVE = 'beforeSave';
    const string EVENT_BEFORE_SEARCH = 'beforeSearch';
    public static int $siteId;
    private mixed $_schema;
    private array $_attributes = ['title', 'url', 'elementHandle', 'content', 'postDate', 'expiryDate', 'noPostDate', 'noExpiryDate'];
    private Element $_element;
    private array $_queryParams = [];
    private array $_highlightParams = [];
    private array $_searchFields = ['attachment.content', 'title'];

    public static function type(): string
    {
        return '_doc';
    }

    /**
     * @inheritdoc
     */
    public function attributes(): array
    {
        return $this->_attributes;
    }

    public function init(): void
    {
        parent::init();

        // add extra fields as additional attributes
        $extraFields = ElasticsearchPlugin::getInstance()->getSettings()->extraFields;
        if (!empty($extraFields)) {
            $this->addAttributes(array_keys($extraFields));
        }
    }

    /**
     * @param bool $runValidation
     * @param null $attributeNames
     * @return bool
     * @throws InvalidConfigException
     * @throws \yii\db\Exception
     * @throws StaleObjectException
     * @throws Exception
     */
    public function save($runValidation = true, $attributeNames = null): bool
    {
        if (!self::indexExists()) {
            $this->createESIndex();
        }

        // Get the value of each extra field
        $extraFields = ElasticsearchPlugin::getInstance()->getSettings()->extraFields;
        if (!empty($extraFields)) {
            foreach ($extraFields as $fieldName => $fieldParams) {
                $fieldValue = ArrayHelper::getValue($fieldParams, 'value');
                if (!empty($fieldValue)) {
                    if (is_callable($fieldValue)) {
                        $this->$fieldName = $fieldValue($this->getElement(), $this);
                    } else {
                        $this->$fieldName = $fieldValue;
                    }
                }
            }
        }

        $this->trigger(self::EVENT_BEFORE_SAVE, new Event());
        if (!$this->getIsNewRecord()) {
            $this->delete(); // pipeline in not supported by Document Update API :(
        }
        return $this->insert($runValidation, $attributeNames, ['pipeline' => 'attachment']);
    }

    /**
     * @return Connection
     * @throws InvalidConfigException
     */
    public static function getDb(): Connection
    {
        return ElasticsearchPlugin::getConnection();
    }

    /**
     * @return string
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public static function index(): string
    {
        if (static::$siteId === null) {
            throw new InvalidConfigException('siteId was not set');
        }

        $elasticIndexNamePrefix = ElasticsearchPlugin::getInstance()->getSettings()->indexNamePrefix;

        $indexName = 'craft-entries_' . static::$siteId;

        if (!empty($elasticIndexNamePrefix)) {
            $indexName = $elasticIndexNamePrefix . '_' . $indexName;
        }

        return $indexName;
    }

    /**
     * Return an array of Elasticsearch records for the given query
     * @param string $query
     * @return ElasticsearchRecord[]
     * @throws InvalidConfigException
     */
    public function search(string $query): array
    {
        // Add extra fields to search parameters
        $extraFields = ElasticsearchPlugin::getInstance()->getSettings()->extraFields;
        $extraHighlighParams = [];
        if (!empty($extraFields)) {
            $this->setSearchFields(ArrayHelper::merge($this->getSearchFields(), array_keys($extraFields)));
            foreach ($extraFields as $fieldName => $fieldParams) {
                $fieldHighlighter = ArrayHelper::getValue($fieldParams, 'highlighter');
                if (!empty($fieldHighlighter)) {
                    $extraHighlighParams[$fieldName] = $fieldHighlighter;
                }
            }
        }
        $highlightParams = $this->getHighlightParams();
        $highlightParams['fields'] = ArrayHelper::merge($highlightParams['fields'], $extraHighlighParams);
        $this->setHighlightParams($highlightParams);

        $this->trigger(self::EVENT_BEFORE_SEARCH, new SearchEvent(['query' => $query]));
        $queryParams = $this->getQueryParams($query);
        $highlightParams = $this->getHighlightParams();
        return self::find()->query($queryParams)->highlight($highlightParams)->limit(self::find()->count())->all();
    }

    /**
     * Try to guess the best Elasticsearch analyze for the current site language
     * @return string
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public static function siteAnalyzer(): string
    {
        if (static::$siteId === null) {
            throw new InvalidConfigException('siteId was not set');
        }

        $analyzer = 'standard'; // Default analyzer
        $availableAnalyzers = [
            'ar'    => 'arabic',
            'hy'    => 'armenian',
            'eu'    => 'basque',
            'bn'    => 'bengali',
            'pt-BR' => 'brazilian',
            'bg'    => 'bulgarian',
            'ca'    => 'catalan',
            'cs'    => 'czech',
            'da'    => 'danish',
            'nl'    => 'dutch',
            'pl'    => 'polish', // analysis-stempel plugin needed
            'en'    => 'english',
            'fi'    => 'finnish',
            'fr'    => 'french',
            'gl'    => 'galician',
            'de'    => 'german',
            'el'    => 'greek',
            'hi'    => 'hindi',
            'hu'    => 'hungarian',
            'id'    => 'indonesian',
            'ga'    => 'irish',
            'it'    => 'italian',
            'ja'    => 'cjk',
            'ko'    => 'cjk',
            'lv'    => 'latvian',
            'lt'    => 'lithuanian',
            'nb'    => 'norwegian',
            'fa'    => 'persian',
            'pt'    => 'portuguese',
            'ro'    => 'romanian',
            'ru'    => 'russian',
            'uk'    => 'ukrainian', // analysis-ukrainian plugin needed
            //sorani, Kurdish language is not part of the Craft locals...
            // 'sk' no analyzer available at this time
            'es'    => 'spanish',
            'sv'    => 'swedish',
            'tr'    => 'turkish',
            'th'    => 'thai',
            'zh'    => 'cjk', //Chinese
        ];

        $siteLanguage = Craft::$app->getSites()->getSiteById(static::$siteId)->language;
        if (array_key_exists($siteLanguage, $availableAnalyzers)) {
            $analyzer = $availableAnalyzers[$siteLanguage];
        } else {
            $localParts = explode('-', $siteLanguage);
            $siteLanguage = $localParts[0];
            if (array_key_exists($siteLanguage, $availableAnalyzers)) {
                $analyzer = $availableAnalyzers[$siteLanguage];
            }
        }

        return $analyzer;
    }

    /**
     * @throws Exception
     * @throws InvalidConfigException
     */
    public function createESIndex(): void
    {
        $mapping = static::mapping();
        // Add extra fields to the mapping definition
        $extraFields = ElasticsearchPlugin::getInstance()->getSettings()->extraFields;
        if (!empty($extraFields)) {
            foreach ($extraFields as $fieldName => $fieldParams) {
                $fieldMapping = ArrayHelper::getValue($fieldParams, 'mapping');
                if ($fieldMapping) {
                    if (is_callable($fieldMapping)) {
                        $fieldMapping = $fieldMapping($this);
                    }
                    $mapping['properties'][$fieldName] = $fieldMapping;
                }
            }
        }
        // Set the schema
        $this->setSchema(
            [
                'mappings' => $mapping,
            ],
        );
        $this->trigger(self::EVENT_BEFORE_CREATE_INDEX, new Event());
        Craft::debug('Before create event - site: ' . self::$siteId . ' schema: ' . VarDumper::dumpAsString($this->getSchema()), __METHOD__);
        self::createIndex($this->getSchema());
    }

    /**
     * Create this model's index in Elasticsearch
     * @param array $schema The Elascticsearch index definition schema
     * @param bool $force
     * @throws InvalidConfigException If the `$siteId` isn't set
     * @throws Exception If an error occurs while communicating with the Elasticsearch server
     */
    public static function createIndex(array $schema, bool $force = false): void
    {
        $db = static::getDb();
        $command = $db->createCommand();

        if ($force === true && $command->indexExists(static::index())) {
            self::deleteIndex();
        }

        $db->delete('_ingest/pipeline/attachment');
        $db->put(
            '_ingest/pipeline/attachment',
            [],
            Json::encode(
                [
                    'description' => 'Extract attachment information',
                    'processors'  => [
                        [
                            'attachment' => [
                                'field'          => 'content',
                                'target_field'   => 'attachment',
                                'indexed_chars'  => -1,
                                'ignore_missing' => true,
                            ],
                            'remove'     => [
                                'field' => 'content',
                            ],
                        ],
                    ],
                ],
            ),
        );

        $db->put(static::index(), ['include_type_name' => 'false'], Json::encode($schema));
    }

    /**
     * Delete this model's index
     * @throws InvalidConfigException|Exception If the `$siteId` isn't set
     */
    public static function deleteIndex(): void
    {
        $db = static::getDb();
        $command = $db->createCommand();
        if ($command->indexExists(static::index())) {
            $command->deleteIndex(static::index());
        }
    }

    /**
     * @return array
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public static function mapping(): array
    {
        $analyzer = self::siteAnalyzer();
        return [
            'properties' => [
                'title'         => [
                    'type'     => 'text',
                    'analyzer' => $analyzer,
                    'store'    => true,
                ],
                'postDate'      => [
                    'type'   => 'date',
                    'format' => 'yyyy-MM-dd HH:mm:ss',
                    'store'  => true,
                ],
                'noPostDate'    => [
                    'type'  => 'boolean',
                    'store' => true,
                ],
                'expiryDate'    => [
                    'type'   => 'date',
                    'format' => 'yyyy-MM-dd HH:mm:ss',
                    'store'  => true,
                ],
                'noExpiryDate'  => [
                    'type'  => 'boolean',
                    'store' => true,
                ],
                'url'           => [
                    'type'  => 'text',
                    'store' => true,
                ],
                'content'       => [
                    'type'     => 'text',
                    'analyzer' => $analyzer,
                    'store'    => true,
                ],
                'elementHandle' => [
                    'type'  => 'keyword',
                    'store' => true,
                ],
                'attachment'    => [
                    'properties' => [
                        'content' => [
                            'type'     => 'text',
                            'analyzer' => $analyzer,
                            'store'    => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return mixed
     */
    public function getSchema(): mixed
    {
        return $this->_schema;
    }

    /**
     * @param mixed $schema
     */
    public function setSchema($schema): void
    {
        $this->_schema = $schema;
    }

    public function addAttributes(array $attributes): void
    {
        $this->_attributes = ArrayHelper::merge($this->_attributes, $attributes);
    }

    public function getElement(): Element
    {
        return $this->_element;
    }

    /**
     * @param mixed $element
     */
    public function setElement(Element $element): void
    {
        $this->_element = $element;
    }

    /**
     * @param string $query
     * @return mixed
     * @throws InvalidConfigException
     */
    public function getQueryParams(string $query): mixed
    {
        if (empty($this->_queryParams)) {
            $currentTimeDb = Db::prepareDateForDb(new \DateTime());
            $this->_queryParams = [
                'bool' => [
                    'must'   => [
                        [
                            'multi_match' => [
                                'fields'   => $this->getSearchFields(),
                                'query'    => $query,
                                'analyzer' => self::siteAnalyzer(),
                                'operator' => 'and',
                            ],
                        ],
                    ],
                    'filter' => [
                        'bool' => [
                            'must' => [
                                [
                                    'range' => [
                                        'postDate' => [
                                            'lte' => $currentTimeDb,
                                        ],
                                    ],
                                ],
                                [
                                    'bool' => [
                                        'should' => [
                                            [
                                                'range' => [
                                                    'expiryDate' => [
                                                        'gt' => $currentTimeDb,
                                                    ],
                                                ],
                                            ],
                                            [
                                                'term' => [
                                                    'noExpiryDate' => true,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],

                            ],
                        ],
                    ],
                ],
            ];
        }
        return $this->_queryParams;
    }

    /**
     * @param array $queryParams
     */
    public function setQueryParams(array $queryParams): void
    {
        $this->_queryParams = $queryParams;
    }

    /**
     * @return array
     */
    public function getHighlightParams(): array
    {
        if (empty($this->_highlightParams)) {
            $this->_highlightParams = ArrayHelper::merge(
                ElasticsearchPlugin::getInstance()->settings->highlight,
                [
                    'fields' => [
                        'attachment.content' => (object)[],
                    ],
                ],
            );
        }
        return $this->_highlightParams;
    }

    /**
     * @param array $highlightParams
     */
    public function setHighlightParams(array $highlightParams): void
    {
        $this->_highlightParams = $highlightParams;
    }

    public function getSearchFields(): array
    {
        return $this->_searchFields;
    }

    public function setSearchFields(array $searchFields): void
    {
        $this->_searchFields = $searchFields;
    }

    /**
     * Return if the Elasticsearch index already exists or not
     * @return bool
     * @throws InvalidConfigException|Exception If the `$siteId` isn't set*
     */
    protected static function indexExists(): bool
    {
        $db = static::getDb();
        $command = $db->createCommand();
        return (bool)$command->indexExists(static::index());
    }
}
