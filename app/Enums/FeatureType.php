<?php

namespace App\Enums;

/**
 * How a plan feature's stored value should be read.
 */
enum FeatureType: string
{
    /** A countable allowance; a null value means unlimited. */
    case Limit = 'limit';

    /** An on/off capability. */
    case Boolean = 'boolean';

    /** A free-form value, e.g. a support tier name. */
    case Text = 'text';
}
