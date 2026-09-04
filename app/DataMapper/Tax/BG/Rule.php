<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\DataMapper\Tax\BG;

use App\DataMapper\Tax\DE\Rule as DERule;

class Rule extends DERule
{
    /** @var string $seller_region */
    public string $seller_region = 'EU';

    /** @var bool $consumer_tax_exempt */
    public bool $consumer_tax_exempt = false;

    /** @var bool $business_tax_exempt */
    public bool $business_tax_exempt = false;

    /** @var bool $eu_business_tax_exempt */
    public bool $eu_business_tax_exempt = true;

    /** @var bool $foreign_business_tax_exempt */
    public bool $foreign_business_tax_exempt = false;

    /** @var bool $foreign_consumer_tax_exempt */
    public bool $foreign_consumer_tax_exempt = false;

    /** @var float $tax_rate */
    public float $tax_rate = 0;

    /** @var float $reduced_tax_rate */
    public float $reduced_tax_rate = 0;

    // payware: upstream ships 'НДС', which is Russian. Bulgarian is 'ДДС'. The name set here
    // is copied into $tax_name by init() and reaches the line table and the totals block of
    // every invoice this rule rates, because calculateRates() only overrides it in the
    // over-threshold B2C branch - which a seller under the 10 000 EUR OSS threshold never
    // enters. Verified on rendered documents 2026-09-04.
    public string $tax_name1 = 'ДДС';

}
