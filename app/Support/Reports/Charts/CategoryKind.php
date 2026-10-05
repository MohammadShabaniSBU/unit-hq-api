<?php

declare(strict_types=1);

namespace App\Support\Reports\Charts;

enum CategoryKind: string
{
    /** Categories are YYYY-MM. The panel formats them with Intl. */
    case Month = 'month';

    /** Categories are i18n keys. */
    case LabelKey = 'label_key';

    /** Categories are verbatim data such as site names or class codes. */
    case Text = 'text';
}
