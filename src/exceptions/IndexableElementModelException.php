<?php

declare(strict_types=1);

namespace oym\elasticsearch\exceptions;

use oym\elasticsearch\models\IndexableElementModel;
use Throwable;

class IndexableElementModelException extends Exception
{
    public const int ELEMENT_NOT_FOUND = 0;
    public const int CRAFT_COMMERCE_NOT_INSTALLED = 1;
    public const int UNEXPECTED_TYPE = 2;
    public const int DIGITAL_PRODUCTS_NOT_INSTALLED = 3;


    public function __construct(IndexableElementModel $model, $code, ?Throwable $previous = null)
    {
        switch ($code) {
            case self::CRAFT_COMMERCE_NOT_INSTALLED:
                $message = sprintf(
                    "Element #%d (site #%d) is a product but the Craft Commerce plugin isn't installed.",
                    $model->elementId,
                    $model->siteId,
                );
                break;
            case self::DIGITAL_PRODUCTS_NOT_INSTALLED:
                $message = sprintf(
                    "Element #%d (site #%d) is a digital product but the Digital Products plugin isn't installed.",
                    $model->elementId,
                    $model->siteId,
                );
                break;
            case self::ELEMENT_NOT_FOUND:
                $message = sprintf(
                    'Element #%d (site #%d) not found (type: %s)',
                    $model->elementId,
                    $model->siteId,
                    $model->type,
                );
                break;
            case self::UNEXPECTED_TYPE:
                $message = sprintf(
                    'Unexpected type (%s) for element #%s (site #%s).',
                    $model->type,
                    $model->elementId,
                    $model->siteId,
                );
                break;
            default:
                $message = 'Unexpected error code';
        }

        parent::__construct($message, $code, $previous);
    }
}
